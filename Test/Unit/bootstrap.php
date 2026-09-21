<?php
/**
 * Copyright © Magenx. All rights reserved.
 * SPDX-License-Identifier: MIT
 *
 * PHPUnit bootstrap for Magenx_Rma.
 *
 * The suite runs against the module in place rather than from inside a Magento
 * root, so the two things an installed application would already have done —
 * building an autoloader and compiling DI's generated classes — are done here.
 */
declare(strict_types=1);

use Magento\Framework\Code\Generator;
use Magento\Framework\Code\Generator\Autoloader;
use Magento\Framework\Code\Generator\Io;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\ObjectManager\Code\Generator\Factory;
use Magento\Framework\ObjectManager\Code\Generator\Proxy;
use Magento\Framework\ObjectManager\Config\Config;
use Magento\Framework\ObjectManager\ConfigInterface;
use Magento\Framework\ObjectManager\Factory\Dynamic\Developer;
use Magento\Framework\ObjectManager\ObjectManager;

$root = dirname(__DIR__, 2);

if (!is_file($root . '/vendor/autoload.php')) {
    throw new RuntimeException(
        'vendor/autoload.php is missing: run "composer install" before the suite. The magento/* packages '
        . 'resolve from repo.magento.com, which needs a Marketplace key pair - in CI those arrive as the '
        . 'MAGENTO_PUBLIC_KEY / MAGENTO_PRIVATE_KEY secrets, passed to the shared workflow with '
        . '"secrets: inherit". Without them composer cannot resolve the framework and the suite cannot run.'
    );
}

require $root . '/vendor/autoload.php';

/*
 * DI writes the *Factory and *Proxy classes it is asked for into generated/code
 * during setup:di:compile; they have no source file anywhere in the repository.
 * This suite mocks several — RMAInterfaceFactory, Item\CollectionFactory,
 * SearchCriteriaBuilderFactory — and PHPUnit cannot double a class that does not
 * exist, so without this every test touching one dies in setUp().
 *
 * Magento's own generator emits them here, byte for byte what a compiled install
 * would hold, rather than hand-written stubs that would drift from it. The
 * generator needs an ObjectManager to build its entity generators and to check
 * the DI configuration for virtual types whose names end in "Factory"; the one
 * below is real, just empty, which is the honest description of a module tested
 * outside an application.
 */
$generatorIo = new Io(new File(), sys_get_temp_dir() . '/magenx-rma-generated-code');

$diConfig = new Config();
$diFactory = new Developer($diConfig);
$sharedInstances = [
    ConfigInterface::class => $diConfig,
    Io::class => $generatorIo,
];
$objectManager = new ObjectManager($diFactory, $diConfig, $sharedInstances);
$diFactory->setObjectManager($objectManager);

$generator = new Generator(
    $generatorIo,
    [
        Factory::ENTITY_TYPE => Factory::class,
        Proxy::ENTITY_TYPE => Proxy::class,
    ]
);
$generator->setObjectManager($objectManager);

// Appended, not prepended: Composer resolves everything with a real source file
// first, and only genuinely absent classes reach the generator.
spl_autoload_register([new Autoloader($generator), 'load']);
