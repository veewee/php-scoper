<?php

declare(strict_types=1);

/*
 * This file is part of the humbug/php-scoper package.
 *
 * Copyright (c) 2017 Théo FIDRY <theo.fidry@gmail.com>,
 *                    Pádraic Brady <padraic.brady@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Humbug\PhpScoper\Scoper;

use Humbug\PhpScoper\Configuration\SymbolsConfiguration;
use Humbug\PhpScoper\PhpParser\FakeParser;
use Humbug\PhpScoper\PhpParser\TraverserFactory;
use Humbug\PhpScoper\Symbol\EnrichedReflector;
use Humbug\PhpScoper\Symbol\Reflector;
use Humbug\PhpScoper\Symbol\SymbolsRegistry;
use LogicException;
use PhpParser\Error as PhpParserError;
use PhpParser\Node\Name;
use PhpParser\NodeTraverserInterface;
use PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use function Humbug\PhpScoper\create_parser;
use function is_a;

class PhpScoperTest extends TestCase
{
    use ProphecyTrait;

    private Scoper $scoper;

    /**
     * @var ObjectProphecy<Scoper>
     */
    private ObjectProphecy $decoratedScoperProphecy;

    private Scoper $decoratedScoper;

    /**
     * @var ObjectProphecy<TraverserFactory>
     */
    private ObjectProphecy $traverserFactoryProphecy;

    private TraverserFactory $traverserFactory;

    /**
     * @var ObjectProphecy<Parser>
     */
    private ObjectProphecy $parserProphecy;

    private Parser $parser;

    protected function setUp(): void
    {
        $this->decoratedScoperProphecy = $this->prophesize(Scoper::class);
        $this->decoratedScoper = $this->decoratedScoperProphecy->reveal();

        $this->traverserFactoryProphecy = $this->prophesize(TraverserFactory::class);
        $this->traverserFactory = $this->traverserFactoryProphecy->reveal();

        $this->parserProphecy = $this->prophesize(Parser::class);
        $this->parser = $this->parserProphecy->reveal();

        $this->scoper = new PhpScoper(
            create_parser(),
            new FakeScoper(),
            new TraverserFactory(
                new EnrichedReflector(
                    Reflector::createEmpty(),
                    SymbolsConfiguration::create(),
                ),
            ),
            'Humbug',
            new SymbolsRegistry(),
        );
    }

    public function test_is_a_Scoper(): void
    {
        self::assertTrue(is_a(PhpScoper::class, Scoper::class, true));
    }

    public function test_can_scope_a_PHP_file(): void
    {
        $filePath = 'file.php';

        $contents = <<<'PHP'
        <?php
        
        echo "Humbug!";
        PHP;

        $expected = <<<'PHP'
        <?php
        
        namespace Humbug;
        
        echo "Humbug!";
        
        PHP;

        $actual = $this->scoper->scope($filePath, $contents);

        self::assertSame($expected, $actual);
    }

    public function test_does_not_scope_file_if_is_not_a_PHP_file(): void
    {
        $filePath = 'file.yaml';
        $fileContents = '';
        $prefix = 'Humbug';

        $this->decoratedScoperProphecy
            ->scope($filePath, $fileContents)
            ->willReturn($expected = 'Scoped content')
        ;

        $this->traverserFactoryProphecy
            ->create(Argument::cetera())
            ->willThrow(new LogicException('Unexpected call.'))
        ;

        $scoper = new PhpScoper(
            new FakeParser(),
            $this->decoratedScoper,
            $this->traverserFactory,
            $prefix,
            new SymbolsRegistry(),
        );

        $actual = $scoper->scope($filePath, $fileContents);

        self::assertSame($expected, $actual);

        $this->decoratedScoperProphecy
            ->scope(Argument::cetera())
            ->shouldHaveBeenCalledTimes(1);
    }

    public function test_can_scope_a_PHP_file_with_the_wrong_extension(): void
    {
        $filePath = 'file';

        $contents = <<<'PHP'
        <?php
        
        echo "Humbug!";
        
        PHP;

        $expected = <<<'PHP'
        <?php
        
        namespace Humbug;
        
        echo "Humbug!";
        
        PHP;

        $actual = $this->scoper->scope($filePath, $contents);

        self::assertSame($expected, $actual);
    }

    public function test_can_scope_PHP_executable_files(): void
    {
        $filePath = 'hello';

        $contents = <<<'PHP'
        #!/usr/bin/env php
        <?php
        
        echo "Hello world";
        PHP;

        $expected = <<<'PHP'
        #!/usr/bin/env php
        <?php 
        namespace Humbug;
        
        echo "Hello world";
        
        PHP;

        $actual = $this->scoper->scope($filePath, $contents);

        self::assertSame($expected, $actual);
    }

    public function test_does_not_scope_a_non_PHP_executable_files(): void
    {
        $prefix = 'Humbug';
        $filePath = 'hello';

        $contents = <<<'PHP'
        #!/usr/bin/env bash
        <?php
        
        echo "Hello world";
        PHP;

        $this->decoratedScoperProphecy
            ->scope($filePath, $contents)
            ->willReturn($expected = 'Scoped content')
        ;

        $this->traverserFactoryProphecy
            ->create(Argument::cetera())
            ->willThrow(new LogicException('Unexpected call.'))
        ;

        $scoper = new PhpScoper(
            new FakeParser(),
            $this->decoratedScoper,
            $this->traverserFactory,
            $prefix,
            new SymbolsRegistry(),
        );

        $actual = $scoper->scope($filePath, $contents);

        self::assertSame($expected, $actual);

        $this->decoratedScoperProphecy
            ->scope(Argument::cetera())
            ->shouldHaveBeenCalledTimes(1);
    }

    public function test_cannot_scope_an_invalid_PHP_file(): void
    {
        $filePath = 'invalid-file.php';
        $contents = <<<'PHP'
        <?php
        
        $class = ;
        
        PHP;

        try {
            $this->scoper->scope($filePath, $contents);

            self::fail('Expected exception to have been thrown.');
        } catch (PhpParserError $error) {
            self::assertEquals(
                'Syntax error, unexpected \';\' on line 3',
                $error->getMessage()
            );
            self::assertSame(0, $error->getCode());
            self::assertNull($error->getPrevious());
        }
    }

    public function test_creates_a_new_traverser_for_each_file(): void
    {
        $files = [
            'file1.php' => 'file1',
            'file2.php' => 'file2',
        ];

        $prefix = 'Humbug';

        $this->decoratedScoperProphecy
            ->scope(Argument::any(), Argument::any())
            ->willReturn('Scoped content')
        ;

        $this->parserProphecy
            ->parse('file1')
            ->willReturn($file1Stmts = [
                new Name('file1'),
            ])
        ;
        $this->parserProphecy
            ->parse('file2')
            ->willReturn($file2Stmts = [
                new Name('file2'),
            ])
        ;

        /** @var ObjectProphecy<NodeTraverserInterface> $firstTraverserProphecy */
        $firstTraverserProphecy = $this->prophesize(NodeTraverserInterface::class);
        $firstTraverserProphecy->traverse($file1Stmts)->willReturn([]);
        /** @var NodeTraverserInterface $firstTraverser */
        $firstTraverser = $firstTraverserProphecy->reveal();

        /** @var ObjectProphecy<NodeTraverserInterface> $secondTraverserProphecy */
        $secondTraverserProphecy = $this->prophesize(NodeTraverserInterface::class);
        $secondTraverserProphecy->traverse($file2Stmts)->willReturn([]);
        /** @var NodeTraverserInterface $secondTraverser */
        $secondTraverser = $secondTraverserProphecy->reveal();

        $i = 0;
        $this->traverserFactoryProphecy
            ->create(
                Argument::type(PhpScoper::class),
                $prefix,
                Argument::that(
                    static function () use (&$i): bool {
                        ++$i;

                        return 1 === $i;
                    }
                ),
            )
            ->willReturn($firstTraverser)
        ;
        $this->traverserFactoryProphecy
            ->create(
                Argument::type(PhpScoper::class),
                $prefix,
                Argument::that(
                    static function () use (&$i): bool {
                        ++$i;

                        return 4 === $i;
                    }
                ),
            )
            ->willReturn($secondTraverser)
        ;

        $scoper = new PhpScoper(
            $this->parser,
            new FakeScoper(),
            $this->traverserFactory,
            $prefix,
            new SymbolsRegistry(),
        );

        foreach ($files as $file => $contents) {
            $scoper->scope($file, $contents);
        }

        $this->parserProphecy
            ->parse(Argument::cetera())
            ->shouldHaveBeenCalledTimes(2);
        $this->traverserFactoryProphecy
            ->create(Argument::cetera())
            ->shouldHaveBeenCalledTimes(2);
        $firstTraverserProphecy
            ->traverse(Argument::cetera())
            ->shouldHaveBeenCalledTimes(1);
        $secondTraverserProphecy
            ->traverse(Argument::cetera())
            ->shouldHaveBeenCalledTimes(1);
    }
}
