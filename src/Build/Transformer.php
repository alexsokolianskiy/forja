<?php

declare(strict_types=1);

namespace Forja\Build;

use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * Produces the guarded copy of a source file: identical text, plus one guard
 * inserted right after the opening brace of each accelerated method.
 *
 * The guard stays on the brace's line, so line numbers in stack traces and
 * __LINE__ are unchanged. __FILE__ and __DIR__ are replaced by the original
 * path, because the copy lives in the cache directory.
 */
final class Transformer
{
    /**
     * @param array<string, array{key: string, params: list<string>, void: bool}> $guards keyed by lower-case "class::method"
     * @return array{code: string, classes: list<string>, guarded: list<string>} guarded holds the keys that were found
     */
    public function transform(string $code, string $file, array $guards): array
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $stmts = $parser->parse($code) ?? [];
        $tokens = $parser->getTokens();
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $stmts = $traverser->traverse($stmts);

        $insertAfter = []; // token index => guard text
        $classes = [];
        $guarded = [];
        foreach ((new NodeFinder())->findInstanceOf($stmts, Stmt\ClassLike::class) as $class) {
            if (!isset($class->namespacedName)) {
                continue;
            }
            $classes[] = $class->namespacedName->toString();
            foreach ($class->getMethods() as $method) {
                $guard = $guards[strtolower($class->namespacedName->toString() . '::' . $method->name->toString())] ?? null;
                if ($guard === null || !$method->stmts) {
                    continue;
                }
                for ($i = $method->stmts[0]->getStartTokenPos() - 1; $i > $method->getStartTokenPos(); $i--) {
                    if ($tokens[$i]->text === '{') {
                        $insertAfter[$i] = $this->guard($guard);
                        $guarded[] = $guard['key'];
                        break;
                    }
                }
            }
        }

        $out = '';
        foreach ($tokens as $i => $token) {
            if ($token->id === 0) {
                continue; // the parser's end-of-file sentinel
            }
            $out .= match ($token->id) {
                \T_FILE => var_export($file, true),
                \T_DIR => var_export(dirname($file), true),
                default => $token->text,
            };
            if (isset($insertAfter[$i])) {
                $out .= $insertAfter[$i];
            }
        }

        return ['code' => $out, 'classes' => $classes, 'guarded' => $guarded];
    }

    /** @param array{key: string, params: list<string>, void: bool} $guard */
    private function guard(array $guard): string
    {
        $key = var_export($guard['key'], true);
        $args = implode(', ', array_map(static fn (string $name): string => '$' . $name, $guard['params']));
        $call = $guard['void'] ? "\$__forja([$args]); return;" : "return \$__forja([$args]);";

        return " if ((\$__forja = \\Forja\\Runtime::native($key)) !== null) { try { $call }"
            . " catch (\\Throwable \$__forjaError) { \\Forja\\Runtime::failed($key, \$__forjaError); } }";
    }
}
