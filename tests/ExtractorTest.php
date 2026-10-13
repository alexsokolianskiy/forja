<?php

declare(strict_types=1);

namespace AiAccel\Tests;

use AiAccel\Abi\Signature;
use AiAccel\Build\Transformer;
use AiAccel\Generator\CodeSanitizer;
use AiAccel\Generator\GenerationException;
use AiAccel\Generator\ModelResolver;
use AiAccel\Generator\ReplyParser;
use AiAccel\Model;
use AiAccel\Parser\MethodExtractor;
use AiAccel\Parser\MethodSpec;
use AiAccel\Tests\Fixtures\Sample;
use PHPUnit\Framework\TestCase;

final class ExtractorTest extends TestCase
{
    /** @return array<string, MethodSpec> */
    private function specs(bool $auto = false): array
    {
        $specs = [];
        foreach ((new MethodExtractor())->extractFromFile(__DIR__ . '/Fixtures/Sample.php', $auto) as $spec) {
            $specs[$spec->method] = $spec;
        }

        return $specs;
    }

    public function testFindsAttributedMethods(): void
    {
        $specs = $this->specs();

        self::assertArrayNotHasKey('plain', $specs);
        self::assertArrayNotHasKey('autoSum', $specs);
        self::assertSame(Sample::class . '::sumSquares', $specs['sumSquares']->key());
        self::assertStringContainsString('$sum += $i * $i;', $specs['sumSquares']->source);
        self::assertFalse($specs['sumSquares']->auto);
    }

    public function testAutoModeAddsTranslatableLoopsOnly(): void
    {
        $specs = $this->specs(auto: true);

        self::assertTrue($specs['autoSum']->auto);
        self::assertNull($specs['autoSum']->unsupported);
        self::assertArrayNotHasKey('autoSkipped', $specs, 'touches $this');
        self::assertArrayNotHasKey('plain', $specs, 'has no loop');
        self::assertArrayNotHasKey('rotate', $specs, 'has no loop');
    }

    public function testMapsTypeHintsAndDocblocksToKinds(): void
    {
        $specs = $this->specs();

        self::assertSame([['name' => 'n', 'kind' => 'int']], $specs['sumSquares']->params);
        self::assertSame(['float[]', 'float[]'], array_column($specs['dot']->params, 'kind'));
        self::assertSame('int[]', $specs['sumList']->params[0]['kind'], 'array<int>');
        self::assertSame('int[][]', $specs['transpose']->params[0]['kind']);
        self::assertSame('int[][]', $specs['transpose']->returnKind);
        self::assertSame('float[][]', $specs['rowSums']->params[0]['kind'], 'list<list<float>>');
        self::assertSame('float[]', $specs['rowSums']->returnKind);
        self::assertSame('string', $specs['caesar']->returnKind);
        self::assertSame('void', $specs['touch']->returnKind);
        self::assertSame('bool', $specs['isPrime']->returnKind);
    }

    public function testReadsAttributeArguments(): void
    {
        $specs = $this->specs();

        self::assertSame(['n' => [0, 500]], $specs['sumSquares']->ranges);
        self::assertNull($specs['sumSquares']->model);
        self::assertFalse($specs['sumSquares']->lock);
        self::assertSame(Model::OPUS, $specs['triple']->model);
        self::assertTrue($specs['triple']->lock);
    }

    public function testCollectsHelpersAndConstants(): void
    {
        $caesar = $this->specs()['caesar'];

        self::assertNull($caesar->unsupported, 'calling same-class helpers through $this and self:: is allowed');
        self::assertStringContainsString('const LETTERS = 26;', $caesar->context);
        self::assertStringContainsString('function rotate(int $code, int $base, int $shift): int', $caesar->context);
        self::assertSame('', $this->specs()['sumSquares']->context);
    }

    public function testExplainsWhyAMethodCannotBeTranslated(): void
    {
        $specs = $this->specs();

        self::assertNull($specs['average']->unsupported, '`throw new` is allowed: C reports an error and PHP throws');
        self::assertStringContainsString('reads or writes a property', (string) $specs['usesThis']->unsupported);
        self::assertStringContainsString('parameter $n needs a type', (string) $specs['untyped']->unsupported);
        self::assertStringContainsString('calls user-defined function userHelper()', (string) $specs['callsUserFunction']->unsupported);
        self::assertStringContainsString('produces output', (string) $specs['prints']->unsupported);
    }

    public function testPrototypeFollowsTheAbi(): void
    {
        $specs = $this->specs();
        $proto = static fn (string $method): string => Signature::fromSpec($specs[$method], str_repeat('ab', 32))->prototype();
        $s = 'accel_abababababababab';

        self::assertSame("double $s(const double *a, size_t a_len, const double *b, size_t b_len, int32_t *accel_err);", $proto('dot'));
        self::assertSame("int64_t *$s(const int64_t *xs, size_t xs_len, int64_t k, int64_t *accel_len, int32_t *accel_err);", $proto('scale'));
        self::assertSame("char *$s(const char *s, size_t s_len, int64_t shift, int64_t *accel_len, int32_t *accel_err);", $proto('caesar'));
        self::assertSame("int64_t *$s(const int64_t *m, size_t m_rows, size_t m_cols, int64_t *accel_rows, int64_t *accel_cols, int32_t *accel_err);", $proto('transpose'));
        self::assertSame("int32_t $s(int64_t n, int32_t *accel_err);", $proto('isPrime'));
        self::assertSame("void $s(int64_t n, int32_t *accel_err);", $proto('touch'));

        $keyword = new Signature('f', [['name' => 'int', 'kind' => 'int'], ['name' => 'default', 'kind' => 'string']], 'int');
        self::assertSame('int64_t f(int64_t int_, const char *default_, size_t default__len, int32_t *accel_err);', $keyword->prototype());
    }

    public function testModelNamesResolveToProviderAndModel(): void
    {
        $providers = ['anthropic' => ['default_model' => 'claude-sonnet-5-5'], 'local' => ['default_model' => 'qwen2.5-coder:14b'], 'bare' => []];
        $models = new ModelResolver($providers, ['fast' => 'local:phi4', 'best' => Model::OPUS, 'mine' => 'fast'], Model::SONNET);

        self::assertSame('anthropic:claude-sonnet-5-5', $models->resolve(null));
        self::assertSame('anthropic:claude-opus-5-5', $models->resolve(Model::OPUS));
        self::assertSame('anthropic:claude-haiku-5-5', $models->resolve('claude-haiku-5-5'), 'bare model name');
        self::assertSame('local:qwen2.5-coder:14b', $models->resolve(Model::LOCAL), 'bare provider name');
        self::assertSame('local:llama3.3:70b', $models->resolve('local:llama3.3:70b'), 'colons inside the model name');
        self::assertSame('local:phi4', $models->resolve('fast'));
        self::assertSame('local:phi4', $models->resolve('mine'), 'alias of an alias');
        self::assertSame('anthropic:claude-opus-5-5', $models->resolve('best'));

        $this->expectException(GenerationException::class);
        $models->resolve('bare');
    }

    public function testExtractsCodeFromModelReplies(): void
    {
        self::assertSame("int x;\n", ReplyParser::extractCode("Here:\n```c\nint x;\n```\n"));
        self::assertSame("int f(void) { return 1; }\n", ReplyParser::extractCode(
            "<think>```c\nnope\n```</think>\n```c\nint f(void) { return 1; }\n```\nExample:\n```c\nf();\n```"
        ));

        $this->expectExceptionMessage('model declined: uses a database');
        ReplyParser::extractCode('UNSUPPORTED: uses a database');
    }

    public function testSanitizerRejectsDangerousCode(): void
    {
        $sanitizer = new CodeSanitizer();
        $sanitizer->check("#include <math.h>\n// system(\"x\") in a comment is fine\ndouble f(double x) { return sqrt(x); }", 'f');

        foreach ([
            "#include <stdio.h>\nint f(void) { return 0; }",
            "#include \"/etc/passwd\"\nint f(void) { return 0; }",
            'int f(void) { return system("id"); }',
            'int f(void) { __asm__("nop"); return 0; }',
            'int g(void) { return 0; }',
        ] as $source) {
            try {
                $sanitizer->check($source, 'f');
                self::fail("accepted: $source");
            } catch (GenerationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testGuardIsInsertedWithoutMovingAnyLine(): void
    {
        $file = __DIR__ . '/Fixtures/Sample.php';
        $code = (string) file_get_contents($file);
        $result = (new Transformer())->transform($code, $file, [
            strtolower(Sample::class . '::sumSquares') => ['key' => Sample::class . '::sumSquares', 'params' => ['n'], 'void' => false],
            strtolower(Sample::class . '::touch') => ['key' => Sample::class . '::touch', 'params' => ['n'], 'void' => true],
        ]);

        self::assertSame([Sample::class], $result['classes']);
        self::assertCount(2, $result['guarded']);
        self::assertSame(substr_count($code, "\n"), substr_count($result['code'], "\n"));
        self::assertStringContainsString("Runtime::native('AiAccel\\\\Tests\\\\Fixtures\\\\Sample::sumSquares')) !== null) { try { return \$__accel([\$n]); }", $result['code']);
        self::assertStringContainsString('{ try { $__accel([$n]); return; }', $result['code']);
        self::assertStringContainsString('return ' . var_export($file, true) . " . '|' . " . var_export(dirname($file), true), $result['code']);

        // Removing the guards and restoring the magic constants gives back the original file.
        $restored = preg_replace('/ if \(\(\$__accel = .*? \} \}/', '', $result['code']);
        $restored = str_replace([var_export($file, true), var_export(dirname($file), true)], ['__FILE__', '__DIR__'], (string) $restored);
        self::assertSame($code, $restored);
    }
}
