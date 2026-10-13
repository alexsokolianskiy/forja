<?php

declare(strict_types=1);

namespace Forja\Parser;

use Forja\Attributes\AccelerateToC;
use PhpParser\ConstExprEvaluator;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/**
 * Finds the methods to accelerate in a PHP file and describes each one as a
 * MethodSpec, including the class constants and helper methods it relies on.
 */
final class MethodExtractor
{
    private const ATTRIBUTE_ARGS = ['model', 'deterministic', 'ranges', 'lock', 'examples'];

    private readonly NodeFinder $finder;
    private readonly Standard $printer;

    public function __construct()
    {
        $this->finder = new NodeFinder();
        $this->printer = new Standard();
    }

    /** @return list<MethodSpec> */
    public function extractFromFile(string $file, bool $auto = false): array
    {
        return $this->extract((string) file_get_contents($file), $file, $auto);
    }

    /**
     * @param bool $auto also return methods without the attribute that contain a loop and are fully translatable
     * @return list<MethodSpec>
     */
    public function extract(string $code, string $file, bool $auto = false): array
    {
        $stmts = (new ParserFactory())->createForHostVersion()->parse($code) ?? [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $stmts = $traverser->traverse($stmts);

        $specs = [];
        foreach ($this->finder->findInstanceOf($stmts, Stmt\Class_::class) as $class) {
            if (!isset($class->namespacedName)) {
                continue; // anonymous class
            }
            foreach ($class->getMethods() as $method) {
                $attribute = $this->findAttribute($method);
                if ($attribute === null && (!$auto || !$this->isAutoCandidate($method))) {
                    continue;
                }
                $spec = $this->buildSpec($class, $method, $attribute, $file);
                if ($attribute !== null || $spec->unsupported === null) {
                    $specs[] = $spec;
                }
            }
        }

        return $specs;
    }

    private function findAttribute(ClassMethod $method): ?Node\Attribute
    {
        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                if ($attr->name->toString() === AccelerateToC::class) {
                    return $attr;
                }
            }
        }

        return null;
    }

    private function isAutoCandidate(ClassMethod $method): bool
    {
        if ($method->stmts === null || str_starts_with($method->name->toString(), '__')) {
            return false;
        }

        return $this->finder->findFirst($method->stmts, static fn (Node $n): bool => $n instanceof Stmt\For_
            || $n instanceof Stmt\Foreach_ || $n instanceof Stmt\While_ || $n instanceof Stmt\Do_) !== null;
    }

    private function buildSpec(Stmt\Class_ $class, ClassMethod $method, ?Node\Attribute $attribute, string $file): MethodSpec
    {
        $options = $attribute ? $this->attributeOptions($attribute) : [];
        $doc = $method->getDocComment()?->getText() ?? '';

        $unsupported = null;
        if (!$method->stmts) {
            $unsupported = 'method has no body';
        } elseif ($class->isAbstract() && !$method->isStatic()) {
            $unsupported = 'non-static method of an abstract class cannot be verified';
        }

        $params = [];
        foreach ($method->params as $param) {
            $name = (string) $param->var->name;
            $kind = null;
            if ($param->byRef || $param->variadic) {
                $unsupported ??= "parameter \$$name is by-reference or variadic";
            } else {
                $kind = $this->kindOf($param->type, $this->docType($doc, '@param', $name));
                $unsupported ??= $kind === null ? "parameter \$$name is an object, callable or other type that has no C form" : null;
            }
            $params[] = ['name' => $name, 'kind' => $kind[0] ?? 'int'] + (isset($kind[1]) ? ['shape' => $kind[1]] : []);
        }

        $return = $this->kindOf($method->returnType, $this->docType($doc, '@return'), true);
        $unsupported ??= $return === null ? 'the return type is an object or other type that has no C form' : null;
        $returnKind = $return[0] ?? null;

        $context = '';
        if ($unsupported === null) {
            [$unsupported, $context] = $this->analyze($class, $method);
        }

        return new MethodSpec(
            class: $class->namespacedName->toString(),
            method: $method->name->toString(),
            file: $file,
            params: $params,
            returnKind: $returnKind ?? 'void',
            source: $this->printer->prettyPrint([$method]),
            context: $context,
            model: $options['model'] ?? null,
            deterministic: $options['deterministic'] ?? true,
            ranges: $options['ranges'] ?? [],
            lock: $options['lock'] ?? false,
            examples: $options['examples'] ?? [],
            auto: $attribute === null,
            unsupported: $unsupported,
        );
    }

    /** @return array<string, mixed> */
    private function attributeOptions(Node\Attribute $attribute): array
    {
        // Class constants such as Model::OPUS are the only non-literal values the attribute is expected to carry.
        $evaluator = new ConstExprEvaluator(static function (Expr $expr) {
            if ($expr instanceof Expr\ClassConstFetch && $expr->class instanceof Node\Name && $expr->name instanceof Node\Identifier) {
                return constant($expr->class->toString() . '::' . $expr->name->toString());
            }
            throw new \RuntimeException('unsupported expression in #[AccelerateToC]');
        });
        $options = [];
        foreach ($attribute->args as $i => $arg) {
            $name = $arg->name?->toString() ?? self::ATTRIBUTE_ARGS[$i] ?? null;
            if ($name !== null) {
                $options[$name] = $evaluator->evaluateSilently($arg->value);
            }
        }

        return $options;
    }

    /**
     * The type written after a docblock tag. Types may contain spaces inside brackets
     * (array{customer: string, amount: float}), so this reads up to the first space outside them.
     *
     * @param string $tag '@return', or '@param' together with the parameter name to look for
     */
    private function docType(string $doc, string $tag, ?string $param = null): ?string
    {
        $offset = 0;
        while (($at = strpos($doc, $tag, $offset)) !== false) {
            $offset = $at + strlen($tag);
            $rest = ltrim(substr($doc, $offset), " \t");
            $type = '';
            $depth = 0;
            for ($i = 0, $n = strlen($rest); $i < $n; $i++) {
                $c = $rest[$i];
                if ($c === "\n" || ($depth === 0 && ($c === ' ' || $c === "\t"))) {
                    break;
                }
                $depth += (int) in_array($c, ['<', '{', '('], true) - (int) in_array($c, ['>', '}', ')'], true);
                $type .= $c;
            }
            if ($type === '' || $type[0] === '$') {
                continue;
            }
            if ($param === null || preg_match('/^\s*\$' . preg_quote($param, '/') . '\b/', substr($rest, $i))) {
                return $type;
            }
        }

        return null;
    }

    /**
     * @return array{0: string, 1: ?string}|null [kind, shape of a mixed value], or null when the type is unsupported
     */
    private function kindOf(?Node $type, ?string $docType, bool $isReturn = false): ?array
    {
        $native = $this->typeString($type);
        if ($native === null || ($native === 'void' && !$isReturn)) {
            return null;
        }
        if (in_array($native, ['int', 'float', 'bool', 'string', 'void'], true)) {
            return [$native, null];
        }
        if ($native === 'array' && $docType !== null) {
            $list = '(?:array|list)<(?:int,)?';
            if (preg_match('/^(?:(int|float)\[\]\[\]|' . $list . $list . '(int|float)>>|' . $list . '(int|float)\[\]>)$/', $docType, $m)) {
                return [($m[1] ?: $m[2] ?: $m[3]) . '[][]', null];
            }
            if (preg_match('/^(?:(int|float)\[\]|' . $list . '(int|float)>)$/', $docType, $m)) {
                return [($m[1] ?: $m[2]) . '[]', null];
            }
        }

        // Anything else made of scalars and arrays travels as a serialized value.
        return ['mixed', $docType ?? ($native === '' ? 'mixed' : $native)];
    }

    /**
     * The declared type as text ('' when there is none), or null when it involves objects,
     * callables or anything else that cannot cross into C.
     */
    private function typeString(?Node $type): ?string
    {
        if ($type === null) {
            return '';
        }
        if ($type instanceof Node\Identifier) {
            $name = strtolower($type->name);

            return in_array($name, ['int', 'float', 'bool', 'string', 'array', 'mixed', 'null', 'false', 'true', 'void'], true) ? $name : null;
        }
        if ($type instanceof Node\NullableType) {
            $inner = $this->typeString($type->type);

            return $inner === null || $inner === 'void' ? null : '?' . $inner;
        }
        if ($type instanceof Node\UnionType) {
            $parts = array_map(fn (Node $t) => $this->typeString($t), $type->types);

            return in_array(null, $parts, true) ? null : implode('|', $parts);
        }

        return null; // class names, intersections
    }

    /**
     * Checks that the method and every same-class method it calls can live in
     * a standalone C file, and collects the source of those helpers and of the
     * class constants involved.
     *
     * @return array{0: ?string, 1: string} [reason it is unsupported, context source]
     */
    private function analyze(Stmt\Class_ $class, ClassMethod $method): array
    {
        $methods = [];
        foreach ($class->getMethods() as $m) {
            $methods[$m->name->toLowerString()] = $m;
        }
        $constants = [];
        foreach ($class->getConstants() as $group) {
            foreach ($group->consts as $const) {
                $constants[$const->name->toString()] = $group;
            }
        }
        $className = $class->namespacedName->toLowerString();

        $self = $method->name->toLowerString();
        $queue = [$self];
        $seen = [$self => true];
        $usedConstants = [];
        while ($queue) {
            $name = array_shift($queue);
            $target = $methods[$name];
            if (!$target->stmts) {
                return ["helper {$target->name}() has no body", ''];
            }
            $calls = [];
            $reason = $this->scan($target->stmts, $className, $methods, $constants, $calls, $usedConstants);
            if ($reason !== null) {
                return [($name === $self ? '' : "helper {$target->name}(): ") . $reason, ''];
            }
            foreach ($calls as $call) {
                if (!isset($seen[$call])) {
                    $seen[$call] = true;
                    $queue[] = $call;
                }
            }
        }

        unset($seen[$self]);
        ksort($seen);
        ksort($usedConstants);
        $nodes = [];
        foreach (array_keys($usedConstants) as $constant) {
            $nodes[spl_object_id($constants[$constant])] = $constants[$constant]; // one `const A = 1, B = 2;` statement, once
        }
        $nodes = array_values($nodes);
        foreach (array_keys($seen) as $helper) {
            $nodes[] = $methods[$helper];
        }

        return [null, $nodes ? $this->printer->prettyPrint($nodes) : ''];
    }

    /**
     * @param Node[] $stmts
     * @param array<string, ClassMethod> $methods
     * @param array<string, Stmt\ClassConst> $constants
     * @param list<string> $calls
     * @param array<string, true> $usedConstants
     */
    private function scan(array $stmts, string $className, array $methods, array $constants, array &$calls, array &$usedConstants): ?string
    {
        $isOwn = static fn (Node $class): bool => $class instanceof Node\Name
            && in_array($class->toLowerString(), ['self', 'static', $className], true);
        $allowed = []; // spl_object_id of nodes that are fine only because of where they appear
        $fail = static fn (Node $node, string $what): string => sprintf('line %d: %s', $node->getStartLine(), $what);

        foreach ($this->finder->find($stmts, static fn (): bool => true) as $node) {
            if ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) {
                $ok = $node instanceof Expr\MethodCall
                    && $node->var instanceof Expr\Variable && $node->var->name === 'this'
                    && $node->name instanceof Node\Identifier && isset($methods[$node->name->toLowerString()]);
                if (!$ok) {
                    return $fail($node, 'calls a method on an object');
                }
                $allowed[spl_object_id($node->var)] = true;
                $calls[] = $node->name->toLowerString();
            } elseif ($node instanceof Expr\StaticCall) {
                if (!$isOwn($node->class) || !$node->name instanceof Node\Identifier || !isset($methods[$node->name->toLowerString()])) {
                    return $fail($node, 'calls a static method of another class');
                }
                $calls[] = $node->name->toLowerString();
            } elseif ($node instanceof Expr\ClassConstFetch) {
                if (!$isOwn($node->class) || !$node->name instanceof Node\Identifier || !isset($constants[$node->name->toString()])) {
                    return $fail($node, 'reads a constant that is not defined in this class');
                }
                $usedConstants[$node->name->toString()] = true;
            } elseif ($node instanceof Expr\FuncCall) {
                $name = $node->name instanceof Node\Name ? $node->name->getLast() : null;
                if ($name === null || !function_exists($name) || !(new \ReflectionFunction($name))->isInternal()) {
                    return $fail($node, $name === null ? 'calls a function dynamically' : "calls user-defined function $name()");
                }
            } elseif ($node instanceof Expr\Throw_ && $node->expr instanceof Expr\New_) {
                $allowed[spl_object_id($node->expr)] = true; // C signals an error instead and PHP then throws for real
            } elseif ($node instanceof Expr\New_ && !isset($allowed[spl_object_id($node)])) {
                return $fail($node, 'creates an object');
            } elseif ($node instanceof Expr\PropertyFetch || $node instanceof Expr\NullsafePropertyFetch || $node instanceof Expr\StaticPropertyFetch) {
                return $fail($node, 'reads or writes a property');
            } elseif ($node instanceof Expr\Variable && $node->name === 'this' && !isset($allowed[spl_object_id($node)])) {
                return $fail($node, 'uses $this');
            } elseif ($node instanceof Expr\Variable && !is_string($node->name)) {
                return $fail($node, 'uses a variable variable');
            } elseif ($node instanceof Stmt\Echo_ || $node instanceof Expr\Print_ || $node instanceof Stmt\InlineHTML || $node instanceof Expr\ShellExec) {
                return $fail($node, 'produces output');
            } elseif ($node instanceof Expr\Yield_ || $node instanceof Expr\YieldFrom || $node instanceof Expr\Eval_
                || $node instanceof Expr\Include_ || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction
                || $node instanceof Expr\Exit_ || $node instanceof Stmt\Global_ || $node instanceof Stmt\Static_) {
                return $fail($node, 'uses ' . $node->getType() . ', which has no C equivalent');
            }
        }

        return null;
    }
}
