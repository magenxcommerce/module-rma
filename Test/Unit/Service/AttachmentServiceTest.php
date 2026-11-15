<?php
/**
 * Mage-OS
 * Copyright (c) Mage-OS Association (https://mage-os.org/)
 * SPDX-License-Identifier: MIT
 *
 * Forked from mage-os/module-rma 2.4.1 into Magenx_Rma / Magenx_RmaGraphQl;
 * identifiers renamed, GraphQL surface split into a sibling module.
 */
declare(strict_types=1);

namespace Magenx\Rma\Test\Unit\Service;

use Magenx\Rma\Api\AttachmentRepositoryInterface;
use Magenx\Rma\Api\Data\AttachmentInterface;
use Magenx\Rma\Api\Data\AttachmentInterfaceFactory;
use Magenx\Rma\Helper\ModuleConfig;
use Magenx\Rma\Model\ResourceModel\Attachment\CollectionFactory as AttachmentCollectionFactory;
use Magenx\Rma\Service\AttachmentService;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\File\Mime;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\MediaStorage\Model\File\UploaderFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AttachmentServiceTest extends TestCase
{
    private WriteInterface&MockObject $varDirectory;
    private AttachmentService $service;

    protected function setUp(): void
    {
        $this->varDirectory = $this->createMock(WriteInterface::class);

        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturn($this->varDirectory);

        $this->service = new AttachmentService(
            $filesystem,
            $this->createMock(UploaderFactory::class),
            $this->createMock(ModuleConfig::class),
            $this->createMock(AttachmentInterfaceFactory::class),
            $this->createMock(AttachmentRepositoryInterface::class),
            $this->createMock(AttachmentCollectionFactory::class),
            $this->createMock(Mime::class),
            $this->createMock(JsonSerializer::class)
        );
    }

    // -------------------------------------------------------------------------
    // getAbsolutePath — path traversal guard
    // -------------------------------------------------------------------------

    public function testGetAbsolutePathReturnsResolvedPathForValidFile(): void
    {
        $attachment = $this->createMock(AttachmentInterface::class);
        $attachment->method('getFilePath')->willReturn('rma/attachments/42/receipt.pdf');

        $this->varDirectory->method('getAbsolutePath')
            ->willReturnMap([
                ['rma/attachments/42/receipt.pdf', '/var/www/html/var/rma/attachments/42/receipt.pdf'],
                [AttachmentService::BASE_PATH,      '/var/www/html/var/rma/attachments'],
            ]);

        $result = $this->service->getAbsolutePath($attachment);

        $this->assertSame('/var/www/html/var/rma/attachments/42/receipt.pdf', $result);
    }

    public function testGetAbsolutePathThrowsOnPathTraversalAttempt(): void
    {
        $this->expectException(LocalizedException::class);

        $attachment = $this->createMock(AttachmentInterface::class);
        $attachment->method('getFilePath')->willReturn('rma/attachments/../../etc/passwd');

        $this->varDirectory->method('getAbsolutePath')
            ->willReturnMap([
                ['rma/attachments/../../etc/passwd', '/var/www/html/var/etc/passwd'],
                [AttachmentService::BASE_PATH,        '/var/www/html/var/rma/attachments'],
            ]);

        $this->service->getAbsolutePath($attachment);
    }

    public function testGetAbsolutePathThrowsWhenPathIsOutsideBaseDir(): void
    {
        $this->expectException(LocalizedException::class);

        $attachment = $this->createMock(AttachmentInterface::class);
        $attachment->method('getFilePath')->willReturn('rma/tmp/malicious.php');

        $this->varDirectory->method('getAbsolutePath')
            ->willReturnMap([
                ['rma/tmp/malicious.php',      '/var/www/html/var/rma/tmp/malicious.php'],
                [AttachmentService::BASE_PATH,  '/var/www/html/var/rma/attachments'],
            ]);

        $this->service->getAbsolutePath($attachment);
    }

    public function testGetAbsolutePathThrowsWhenPathMatchesPrefixButIsNotChild(): void
    {
        $this->expectException(LocalizedException::class);

        // Without the trailing-slash guard, str_starts_with('/var/.../rma/attachments-evil/...', '/var/.../rma/attachments')
        // would return TRUE and allow the path through. The fix normalises basePath to end with '/'.
        $attachment = $this->createMock(AttachmentInterface::class);
        $attachment->method('getFilePath')->willReturn('rma/attachments-evil/file.php');

        $this->varDirectory->method('getAbsolutePath')
            ->willReturnMap([
                ['rma/attachments-evil/file.php', '/var/www/html/var/rma/attachments-evil/file.php'],
                [AttachmentService::BASE_PATH,     '/var/www/html/var/rma/attachments'],
            ]);

        $this->service->getAbsolutePath($attachment);
    }

    // -------------------------------------------------------------------------
    // formatFileSize
    // -------------------------------------------------------------------------

    /**
     * @dataProvider fileSizeProvider
     */
    public function testFormatFileSize(int $bytes, string $expected): void
    {
        $this->assertSame($expected, $this->service->formatFileSize($bytes));
    }

    public static function fileSizeProvider(): array
    {
        return [
            'bytes'      => [512,           '512 B'],
            'exactly 1KB'=> [1024,          '1 KB'],
            'kilobytes'  => [2048,          '2 KB'],
            'megabytes'  => [1048576,       '1 MB'],
            'fractional' => [1572864,       '1.5 MB'],
            'zero bytes' => [0,             '0 B'],
        ];
    }

    public function testMoreFilesThanAllowedRefuseTheWholeUpload(): void
    {
        $config = $this->createMock(ModuleConfig::class);
        $config->method('getMaxAttachmentFiles')->willReturn(5);
        $repository = $this->createMock(AttachmentRepositoryInterface::class);
        $repository->expects($this->never())->method('save');
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturn($this->varDirectory);
        $this->varDirectory->expects($this->never())->method('renameFile');

        $service = new AttachmentService(
            $filesystem,
            $this->createMock(UploaderFactory::class),
            $config,
            $this->createMock(AttachmentInterfaceFactory::class),
            $repository,
            $this->createMock(AttachmentCollectionFactory::class),
            $this->createMock(Mime::class),
            $this->createMock(JsonSerializer::class)
        );

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/at most 5 files/');

        $service->moveFromTmpAndSave(1, array_fill(0, 6, ['file' => 'a.pdf', 'name' => 'a.pdf']));
    }

    public function testFilesAreTakenFromTheUploadersOwnFolderWithTheStoredSize(): void
    {
        $config = $this->createMock(ModuleConfig::class);
        $config->method('getMaxAttachmentFiles')->willReturn(5);
        $attachment = $this->createMock(AttachmentInterface::class);
        $attachment->expects($this->once())->method('setFileSize')->with(2048);
        $factory = $this->createMock(AttachmentInterfaceFactory::class);
        $factory->method('create')->willReturn($attachment);
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturn($this->varDirectory);

        $this->varDirectory->expects($this->once())->method('isExist')
            ->with('rma/tmp/admin_5/invoice.pdf')->willReturn(true);
        $this->varDirectory->expects($this->once())->method('renameFile')
            ->with('rma/tmp/admin_5/invoice.pdf', 'rma/attachments/9/invoice.pdf');
        $this->varDirectory->method('stat')->willReturn(['size' => 2048]);
        $mime = $this->createMock(Mime::class);
        $mime->method('getMimeType')->willReturn('application/pdf');

        $service = new AttachmentService(
            $filesystem,
            $this->createMock(UploaderFactory::class),
            $config,
            $factory,
            $this->createMock(AttachmentRepositoryInterface::class),
            $this->createMock(AttachmentCollectionFactory::class),
            $mime,
            $this->createMock(JsonSerializer::class)
        );

        $service->moveFromTmpAndSave(9, [['file' => '../invoice.pdf', 'name' => 'invoice.pdf', 'size' => 1]], null, 0, 'admin_5');
    }
}
