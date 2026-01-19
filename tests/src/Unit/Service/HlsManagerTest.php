<?php

declare(strict_types=1);

namespace Drupal\Tests\islandora_hls\Unit\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Drupal\islandora_hls\Service\HlsManager;
use Drupal\media\MediaInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Tests the HlsManager service.
 *
 * @group islandora_hls
 * @coversDefaultClass \Drupal\islandora_hls\Service\HlsManager
 */
class HlsManagerTest extends UnitTestCase {

  /**
   * The mocked entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected EntityTypeManagerInterface|MockObject $entityTypeManager;

  /**
   * The mocked logger.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected LoggerInterface|MockObject $logger;

  /**
   * The mocked file system.
   *
   * @var \Drupal\Core\File\FileSystemInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected FileSystemInterface|MockObject $fileSystem;

  /**
   * The mocked database connection.
   *
   * @var \Drupal\Core\Database\Connection|\PHPUnit\Framework\MockObject\MockObject
   */
  protected Connection|MockObject $database;

  /**
   * The HlsManager instance under test.
   *
   * @var \Drupal\islandora_hls\Service\HlsManager
   */
  protected HlsManager $hlsManager;

  /**
   * Path to test fixtures.
   *
   * @var string
   */
  protected string $fixturesPath;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->logger = $this->createMock(LoggerInterface::class);
    $this->fileSystem = $this->createMock(FileSystemInterface::class);
    $this->database = $this->createMock(Connection::class);

    $this->hlsManager = new HlsManager(
      $this->entityTypeManager,
      $this->logger,
      $this->fileSystem,
      $this->database
    );

    $this->fixturesPath = dirname(__DIR__, 3) . '/fixtures';
  }

  /**
   * Tests that presave skips non-audio/video media bundles.
   *
   * @covers ::presave
   */
  public function testPresaveSkipsNonAudioVideoBundle(): void {
    $media = $this->createMock(MediaInterface::class);
    $media->method('bundle')->willReturn('image');

    // Should not call any other methods.
    $media->expects($this->never())->method('hasField');

    $this->hlsManager->presave($media);
  }

  /**
   * Tests that presave skips media without field_hls_assets.
   *
   * @covers ::presave
   */
  public function testPresaveSkipsMediaWithoutHlsAssetsField(): void {
    $media = $this->createMock(MediaInterface::class);
    $media->method('bundle')->willReturn('video');
    $media->method('hasField')
      ->willReturnCallback(function ($field) {
        return $field !== 'field_hls_assets';
      });

    // Should not try to get the file field.
    $media->expects($this->never())->method('get');

    $this->hlsManager->presave($media);
  }

  /**
   * Tests that presave skips media without the media file field.
   *
   * @covers ::presave
   */
  public function testPresaveSkipsMediaWithoutFileField(): void {
    $media = $this->createMock(MediaInterface::class);
    $media->method('bundle')->willReturn('video');
    $media->method('hasField')
      ->willReturnCallback(function ($field) {
        if ($field === 'field_hls_assets') {
          return TRUE;
        }
        if ($field === 'field_media_video_file') {
          return FALSE;
        }
        return FALSE;
      });

    $this->hlsManager->presave($media);
  }

  /**
   * Tests that presave skips media with empty file field.
   *
   * @covers ::presave
   */
  public function testPresaveSkipsMediaWithEmptyFileField(): void {
    $fieldItemList = $this->createMock(FieldItemListInterface::class);
    $fieldItemList->method('isEmpty')->willReturn(TRUE);

    $media = $this->createMock(MediaInterface::class);
    $media->method('bundle')->willReturn('audio');
    $media->method('hasField')->willReturn(TRUE);
    $media->method('get')
      ->with('field_media_audio_file')
      ->willReturn($fieldItemList);

    $this->hlsManager->presave($media);
  }

  /**
   * Tests that presave skips non-m3u8 files.
   *
   * @covers ::presave
   */
  public function testPresaveSkipsNonM3u8Files(): void {
    $file = $this->createMock(FileInterface::class);
    $file->method('getFileUri')->willReturn('public://video.mp4');

    $fieldItemList = $this->createMock(FieldItemListInterface::class);
    $fieldItemList->method('isEmpty')->willReturn(FALSE);
    $fieldItemList->entity = $file;

    $media = $this->createMock(MediaInterface::class);
    $media->method('bundle')->willReturn('video');
    $media->method('hasField')->willReturn(TRUE);
    $media->method('get')
      ->willReturnCallback(function ($field) use ($fieldItemList) {
        if ($field === 'field_media_video_file') {
          return $fieldItemList;
        }
        return $this->createMock(FieldItemListInterface::class);
      });

    // Should not call fileSystem->dirname since we exit early.
    $this->fileSystem->expects($this->never())->method('dirname');

    $this->hlsManager->presave($media);
  }

  /**
   * Tests parsing a master m3u8 with multiple variant playlists.
   *
   * @covers ::presave
   */
  public function testPresaveParsesMasterPlaylist(): void {
    $masterUri = 'public://hls/master.m3u8';
    $baseDir = 'public://hls';

    $file = $this->createMock(FileInterface::class);
    $file->method('getFileUri')->willReturn($masterUri);

    $fileFieldItemList = $this->createMock(FieldItemListInterface::class);
    $fileFieldItemList->method('isEmpty')->willReturn(FALSE);
    $fileFieldItemList->entity = $file;

    $hlsAssetsFieldItemList = $this->createMock(FieldItemListInterface::class);
    $hlsAssetsFieldItemList->method('getValue')->willReturn([]);

    $media = $this->createMock(MediaInterface::class);
    $media->method('bundle')->willReturn('video');
    $media->method('hasField')->willReturn(TRUE);
    $media->method('get')
      ->willReturnCallback(function ($field) use ($fileFieldItemList, $hlsAssetsFieldItemList) {
        if ($field === 'field_media_video_file') {
          return $fileFieldItemList;
        }
        if ($field === 'field_hls_assets') {
          return $hlsAssetsFieldItemList;
        }
        return $this->createMock(FieldItemListInterface::class);
      });

    $this->fileSystem->method('dirname')->willReturn($baseDir);
    $this->fileSystem->method('realpath')
      ->willReturnCallback(function ($uri) {
        $filename = basename($uri);
        return $this->fixturesPath . '/' . $filename;
      });
    $this->fileSystem->method('basename')
      ->willReturnCallback(function ($uri) {
        return basename($uri);
      });

    // Mock database to return file IDs for each asset.
    $fileIdMap = [
      '360p.m3u8' => 1,
      '480p.m3u8' => 2,
      '720p.m3u8' => 3,
      'segment_360p_0.ts' => 4,
      'segment_360p_1.ts' => 5,
      'segment_360p_2.ts' => 6,
      'segment_480p_0.ts' => 7,
      'segment_480p_1.ts' => 8,
      'segment_480p_2.ts' => 9,
      'segment_720p_0.ts' => 10,
      'segment_720p_1.ts' => 11,
      'segment_720p_2.ts' => 12,
    ];

    $this->database->method('escapeLike')
      ->willReturnCallback(function ($string) {
        return $string;
      });

    $this->database->method('query')
      ->willReturnCallback(function ($query, $args) use ($fileIdMap) {
        $pattern = $args[':pattern'];
        // Extract filename from pattern like '%/360p.m3u8'.
        $filename = substr($pattern, 2);
        $fid = $fileIdMap[$filename] ?? FALSE;

        $statement = $this->createMock(StatementInterface::class);
        $statement->method('fetchField')->willReturn($fid);
        return $statement;
      });

    // Mock file storage to return file entities.
    $fileStorage = $this->createMock(EntityStorageInterface::class);
    $fileStorage->method('load')
      ->willReturnCallback(function ($fid) use ($baseDir) {
        $file = $this->createMock(FileInterface::class);
        $file->method('id')->willReturn($fid);
        // Files are already in the correct directory.
        $file->method('getFileUri')->willReturn($baseDir . '/file_' . $fid . '.ts');
        return $file;
      });

    $this->entityTypeManager->method('getStorage')
      ->with('file')
      ->willReturn($fileStorage);

    // Expect field_hls_assets to be set with 12 files (3 m3u8 + 9 ts).
    $media->expects($this->once())
      ->method('set')
      ->with('field_hls_assets', $this->callback(function ($value) {
        return count($value) === 12;
      }));

    $this->hlsManager->presave($media);
  }

  /**
   * Tests that presave logs error for missing files.
   *
   * @covers ::presave
   */
  public function testPresaveLogsMissingFiles(): void {
    $masterUri = 'public://hls/single_variant.m3u8';
    $baseDir = 'public://hls';

    $file = $this->createMock(FileInterface::class);
    $file->method('getFileUri')->willReturn($masterUri);

    $fileFieldItemList = $this->createMock(FieldItemListInterface::class);
    $fileFieldItemList->method('isEmpty')->willReturn(FALSE);
    $fileFieldItemList->entity = $file;

    $hlsAssetsFieldItemList = $this->createMock(FieldItemListInterface::class);
    $hlsAssetsFieldItemList->method('getValue')->willReturn([]);

    $media = $this->createMock(MediaInterface::class);
    $media->method('bundle')->willReturn('video');
    $media->method('hasField')->willReturn(TRUE);
    $media->method('get')
      ->willReturnCallback(function ($field) use ($fileFieldItemList, $hlsAssetsFieldItemList) {
        if ($field === 'field_media_video_file') {
          return $fileFieldItemList;
        }
        if ($field === 'field_hls_assets') {
          return $hlsAssetsFieldItemList;
        }
        return $this->createMock(FieldItemListInterface::class);
      });

    $this->fileSystem->method('dirname')->willReturn($baseDir);
    $this->fileSystem->method('realpath')
      ->willReturnCallback(function ($uri) {
        $filename = basename($uri);
        return $this->fixturesPath . '/' . $filename;
      });

    $this->database->method('escapeLike')
      ->willReturnCallback(fn($s) => $s);

    // Return FALSE for all files (not found in database).
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchField')->willReturn(FALSE);
    $this->database->method('query')->willReturn($statement);

    // Expect error logging for missing files.
    $this->logger->expects($this->exactly(2))
      ->method('error')
      ->with(
        'HLS asset file not found in database: @filename',
        $this->callback(function ($context) {
          return isset($context['@filename']) &&
            in_array($context['@filename'], ['segment0.ts', 'segment1.ts']);
        })
      );

    $media->expects($this->once())
      ->method('set')
      ->with('field_hls_assets', []);

    $this->hlsManager->presave($media);
  }

  /**
   * Tests that presave deletes old files no longer referenced.
   *
   * @covers ::presave
   */
  public function testPresaveDeletesOldFiles(): void {
    $masterUri = 'public://hls/single_variant.m3u8';
    $baseDir = 'public://hls';

    $file = $this->createMock(FileInterface::class);
    $file->method('getFileUri')->willReturn($masterUri);

    $fileFieldItemList = $this->createMock(FieldItemListInterface::class);
    $fileFieldItemList->method('isEmpty')->willReturn(FALSE);
    $fileFieldItemList->entity = $file;

    // Existing assets include an old file (fid 99) that's no longer referenced.
    $hlsAssetsFieldItemList = $this->createMock(FieldItemListInterface::class);
    $hlsAssetsFieldItemList->method('getValue')->willReturn([
      ['target_id' => 1],
      ['target_id' => 2],
      ['target_id' => 99],
    ]);

    $media = $this->createMock(MediaInterface::class);
    $media->method('bundle')->willReturn('video');
    $media->method('hasField')->willReturn(TRUE);
    $media->method('get')
      ->willReturnCallback(function ($field) use ($fileFieldItemList, $hlsAssetsFieldItemList) {
        if ($field === 'field_media_video_file') {
          return $fileFieldItemList;
        }
        if ($field === 'field_hls_assets') {
          return $hlsAssetsFieldItemList;
        }
        return $this->createMock(FieldItemListInterface::class);
      });

    $this->fileSystem->method('dirname')->willReturn($baseDir);
    $this->fileSystem->method('realpath')
      ->willReturnCallback(function ($uri) {
        $filename = basename($uri);
        return $this->fixturesPath . '/' . $filename;
      });
    $this->fileSystem->method('basename')
      ->willReturnCallback(fn($uri) => basename($uri));

    $this->database->method('escapeLike')
      ->willReturnCallback(fn($s) => $s);

    // Map filenames to fids.
    $filenameToFid = [
      'segment0.ts' => 1,
      'segment1.ts' => 2,
    ];

    $this->database->method('query')
      ->willReturnCallback(function ($query, $args) use ($filenameToFid) {
        $pattern = $args[':pattern'];
        $filename = substr($pattern, 2);
        $fid = $filenameToFid[$filename] ?? FALSE;

        $statement = $this->createMock(StatementInterface::class);
        $statement->method('fetchField')->willReturn($fid);
        return $statement;
      });

    // Mock file to be deleted (fid 99).
    $fileToDelete = $this->createMock(FileInterface::class);
    $fileToDelete->expects($this->once())->method('delete');

    // Mock file storage.
    $fileStorage = $this->createMock(EntityStorageInterface::class);
    $fileStorage->method('load')
      ->willReturnCallback(function ($fid) use ($baseDir, $fileToDelete) {
        if ($fid === 99) {
          return $fileToDelete;
        }
        $file = $this->createMock(FileInterface::class);
        $file->method('id')->willReturn($fid);
        $file->method('getFileUri')->willReturn($baseDir . '/segment.ts');
        return $file;
      });

    $this->entityTypeManager->method('getStorage')
      ->with('file')
      ->willReturn($fileStorage);

    $this->hlsManager->presave($media);
  }

  /**
   * Tests that presave moves files to correct directory.
   *
   * @covers ::presave
   */
  public function testPresaveMovesFilesToCorrectDirectory(): void {
    $masterUri = 'public://hls/2024-01-15/single_variant.m3u8';
    $baseDir = 'public://hls/2024-01-15';
    $wrongDir = 'public://hls/2024-01-14';

    $masterFile = $this->createMock(FileInterface::class);
    $masterFile->method('getFileUri')->willReturn($masterUri);

    $fileFieldItemList = $this->createMock(FieldItemListInterface::class);
    $fileFieldItemList->method('isEmpty')->willReturn(FALSE);
    $fileFieldItemList->entity = $masterFile;

    $hlsAssetsFieldItemList = $this->createMock(FieldItemListInterface::class);
    $hlsAssetsFieldItemList->method('getValue')->willReturn([]);

    $media = $this->createMock(MediaInterface::class);
    $media->method('bundle')->willReturn('video');
    $media->method('hasField')->willReturn(TRUE);
    $media->method('get')
      ->willReturnCallback(function ($field) use ($fileFieldItemList, $hlsAssetsFieldItemList) {
        if ($field === 'field_media_video_file') {
          return $fileFieldItemList;
        }
        if ($field === 'field_hls_assets') {
          return $hlsAssetsFieldItemList;
        }
        return $this->createMock(FieldItemListInterface::class);
      });

    $this->fileSystem->method('dirname')
      ->willReturnCallback(function ($uri) use ($baseDir, $wrongDir) {
        if (str_contains($uri, '2024-01-15')) {
          return $baseDir;
        }
        return $wrongDir;
      });

    $this->fileSystem->method('realpath')
      ->willReturnCallback(function ($uri) {
        $filename = basename($uri);
        return $this->fixturesPath . '/' . $filename;
      });

    $this->fileSystem->method('basename')
      ->willReturnCallback(fn($uri) => basename($uri));

    $this->fileSystem->method('prepareDirectory')
      ->willReturn(TRUE);

    // File should be moved.
    $this->fileSystem->expects($this->exactly(2))
      ->method('move')
      ->willReturnCallback(function ($source, $dest) {
        return $dest;
      });

    $this->database->method('escapeLike')
      ->willReturnCallback(fn($s) => $s);

    $filenameToFid = [
      'segment0.ts' => 1,
      'segment1.ts' => 2,
    ];

    $this->database->method('query')
      ->willReturnCallback(function ($query, $args) use ($filenameToFid) {
        $pattern = $args[':pattern'];
        $filename = substr($pattern, 2);
        $fid = $filenameToFid[$filename] ?? FALSE;

        $statement = $this->createMock(StatementInterface::class);
        $statement->method('fetchField')->willReturn($fid);
        return $statement;
      });

    // Files are in the wrong directory.
    $fileStorage = $this->createMock(EntityStorageInterface::class);
    $fileStorage->method('load')
      ->willReturnCallback(function ($fid) use ($wrongDir) {
        $file = $this->createMock(FileInterface::class);
        $file->method('id')->willReturn($fid);
        $file->method('getFileUri')->willReturn($wrongDir . '/segment' . ($fid - 1) . '.ts');
        $file->expects($this->once())->method('setFileUri');
        $file->expects($this->once())->method('save');
        return $file;
      });

    $this->entityTypeManager->method('getStorage')
      ->with('file')
      ->willReturn($fileStorage);

    $this->hlsManager->presave($media);
  }

  /**
   * Tests that presave handles audio media bundles.
   *
   * @covers ::presave
   */
  public function testPresaveHandlesAudioBundle(): void {
    $masterUri = 'public://hls/single_variant.m3u8';
    $baseDir = 'public://hls';

    $file = $this->createMock(FileInterface::class);
    $file->method('getFileUri')->willReturn($masterUri);

    $fileFieldItemList = $this->createMock(FieldItemListInterface::class);
    $fileFieldItemList->method('isEmpty')->willReturn(FALSE);
    $fileFieldItemList->entity = $file;

    $hlsAssetsFieldItemList = $this->createMock(FieldItemListInterface::class);
    $hlsAssetsFieldItemList->method('getValue')->willReturn([]);

    $media = $this->createMock(MediaInterface::class);
    $media->method('bundle')->willReturn('audio');
    $media->method('hasField')->willReturn(TRUE);
    $media->method('get')
      ->willReturnCallback(function ($field) use ($fileFieldItemList, $hlsAssetsFieldItemList) {
        // Audio uses field_media_audio_file.
        if ($field === 'field_media_audio_file') {
          return $fileFieldItemList;
        }
        if ($field === 'field_hls_assets') {
          return $hlsAssetsFieldItemList;
        }
        return $this->createMock(FieldItemListInterface::class);
      });

    $this->fileSystem->method('dirname')->willReturn($baseDir);
    $this->fileSystem->method('realpath')
      ->willReturnCallback(function ($uri) {
        $filename = basename($uri);
        return $this->fixturesPath . '/' . $filename;
      });
    $this->fileSystem->method('basename')
      ->willReturnCallback(fn($uri) => basename($uri));

    $this->database->method('escapeLike')
      ->willReturnCallback(fn($s) => $s);

    $filenameToFid = [
      'segment0.ts' => 1,
      'segment1.ts' => 2,
    ];

    $this->database->method('query')
      ->willReturnCallback(function ($query, $args) use ($filenameToFid) {
        $pattern = $args[':pattern'];
        $filename = substr($pattern, 2);
        $fid = $filenameToFid[$filename] ?? FALSE;

        $statement = $this->createMock(StatementInterface::class);
        $statement->method('fetchField')->willReturn($fid);
        return $statement;
      });

    $fileStorage = $this->createMock(EntityStorageInterface::class);
    $fileStorage->method('load')
      ->willReturnCallback(function ($fid) use ($baseDir) {
        $file = $this->createMock(FileInterface::class);
        $file->method('id')->willReturn($fid);
        $file->method('getFileUri')->willReturn($baseDir . '/segment.ts');
        return $file;
      });

    $this->entityTypeManager->method('getStorage')
      ->with('file')
      ->willReturn($fileStorage);

    // Verify field_media_audio_file is used for audio bundle.
    $media->expects($this->once())
      ->method('set')
      ->with('field_hls_assets', $this->callback(function ($value) {
        return count($value) === 2;
      }));

    $this->hlsManager->presave($media);
  }

  /**
   * Tests that presave handles unreadable m3u8 file.
   *
   * @covers ::presave
   */
  public function testPresaveHandlesUnreadableM3u8(): void {
    $masterUri = 'public://hls/nonexistent.m3u8';
    $baseDir = 'public://hls';

    $file = $this->createMock(FileInterface::class);
    $file->method('getFileUri')->willReturn($masterUri);

    $fileFieldItemList = $this->createMock(FieldItemListInterface::class);
    $fileFieldItemList->method('isEmpty')->willReturn(FALSE);
    $fileFieldItemList->entity = $file;

    $hlsAssetsFieldItemList = $this->createMock(FieldItemListInterface::class);
    $hlsAssetsFieldItemList->method('getValue')->willReturn([]);

    $media = $this->createMock(MediaInterface::class);
    $media->method('bundle')->willReturn('video');
    $media->method('hasField')->willReturn(TRUE);
    $media->method('get')
      ->willReturnCallback(function ($field) use ($fileFieldItemList, $hlsAssetsFieldItemList) {
        if ($field === 'field_media_video_file') {
          return $fileFieldItemList;
        }
        if ($field === 'field_hls_assets') {
          return $hlsAssetsFieldItemList;
        }
        return $this->createMock(FieldItemListInterface::class);
      });

    $this->fileSystem->method('dirname')->willReturn($baseDir);
    // Return FALSE for realpath to simulate unreadable file.
    $this->fileSystem->method('realpath')->willReturn(FALSE);

    $this->logger->expects($this->once())
      ->method('error')
      ->with('Cannot read m3u8 file: @uri', ['@uri' => $masterUri]);

    // Should not set field_hls_assets since no files were found.
    $media->expects($this->never())->method('set');

    $this->hlsManager->presave($media);
  }

  /**
   * Tests that presave handles move failure gracefully.
   *
   * @covers ::presave
   */
  public function testPresaveHandlesMoveFailure(): void {
    $masterUri = 'public://hls/2024-01-15/single_variant.m3u8';
    $baseDir = 'public://hls/2024-01-15';
    $wrongDir = 'public://hls/2024-01-14';

    $masterFile = $this->createMock(FileInterface::class);
    $masterFile->method('getFileUri')->willReturn($masterUri);

    $fileFieldItemList = $this->createMock(FieldItemListInterface::class);
    $fileFieldItemList->method('isEmpty')->willReturn(FALSE);
    $fileFieldItemList->entity = $masterFile;

    $hlsAssetsFieldItemList = $this->createMock(FieldItemListInterface::class);
    $hlsAssetsFieldItemList->method('getValue')->willReturn([]);

    $media = $this->createMock(MediaInterface::class);
    $media->method('bundle')->willReturn('video');
    $media->method('hasField')->willReturn(TRUE);
    $media->method('get')
      ->willReturnCallback(function ($field) use ($fileFieldItemList, $hlsAssetsFieldItemList) {
        if ($field === 'field_media_video_file') {
          return $fileFieldItemList;
        }
        if ($field === 'field_hls_assets') {
          return $hlsAssetsFieldItemList;
        }
        return $this->createMock(FieldItemListInterface::class);
      });

    $this->fileSystem->method('dirname')
      ->willReturnCallback(function ($uri) use ($baseDir, $wrongDir) {
        if (str_contains($uri, '2024-01-15')) {
          return $baseDir;
        }
        return $wrongDir;
      });

    $this->fileSystem->method('realpath')
      ->willReturnCallback(function ($uri) {
        $filename = basename($uri);
        return $this->fixturesPath . '/' . $filename;
      });

    $this->fileSystem->method('basename')
      ->willReturnCallback(fn($uri) => basename($uri));

    $this->fileSystem->method('prepareDirectory')->willReturn(TRUE);

    // Simulate move failure.
    $this->fileSystem->method('move')
      ->willThrowException(new \Exception('Permission denied'));

    $this->database->method('escapeLike')
      ->willReturnCallback(fn($s) => $s);

    $filenameToFid = [
      'segment0.ts' => 1,
      'segment1.ts' => 2,
    ];

    $this->database->method('query')
      ->willReturnCallback(function ($query, $args) use ($filenameToFid) {
        $pattern = $args[':pattern'];
        $filename = substr($pattern, 2);
        $fid = $filenameToFid[$filename] ?? FALSE;

        $statement = $this->createMock(StatementInterface::class);
        $statement->method('fetchField')->willReturn($fid);
        return $statement;
      });

    $fileStorage = $this->createMock(EntityStorageInterface::class);
    $fileStorage->method('load')
      ->willReturnCallback(function ($fid) use ($wrongDir) {
        $file = $this->createMock(FileInterface::class);
        $file->method('id')->willReturn($fid);
        $file->method('getFileUri')->willReturn($wrongDir . '/segment' . ($fid - 1) . '.ts');
        return $file;
      });

    $this->entityTypeManager->method('getStorage')
      ->with('file')
      ->willReturn($fileStorage);

    // Expect error logging for move failures.
    $this->logger->expects($this->exactly(2))
      ->method('error')
      ->with(
        'Failed to move file from @source to @dest: @error',
        $this->isType('array')
      );

    // No files should be added since all moves failed.
    $media->expects($this->once())
      ->method('set')
      ->with('field_hls_assets', []);

    $this->hlsManager->presave($media);
  }

  /**
   * Tests that presave handles directory preparation failure.
   *
   * @covers ::presave
   */
  public function testPresaveHandlesDirectoryPreparationFailure(): void {
    $masterUri = 'public://hls/2024-01-15/single_variant.m3u8';
    $baseDir = 'public://hls/2024-01-15';
    $wrongDir = 'public://hls/2024-01-14';

    $masterFile = $this->createMock(FileInterface::class);
    $masterFile->method('getFileUri')->willReturn($masterUri);

    $fileFieldItemList = $this->createMock(FieldItemListInterface::class);
    $fileFieldItemList->method('isEmpty')->willReturn(FALSE);
    $fileFieldItemList->entity = $masterFile;

    $hlsAssetsFieldItemList = $this->createMock(FieldItemListInterface::class);
    $hlsAssetsFieldItemList->method('getValue')->willReturn([]);

    $media = $this->createMock(MediaInterface::class);
    $media->method('bundle')->willReturn('video');
    $media->method('hasField')->willReturn(TRUE);
    $media->method('get')
      ->willReturnCallback(function ($field) use ($fileFieldItemList, $hlsAssetsFieldItemList) {
        if ($field === 'field_media_video_file') {
          return $fileFieldItemList;
        }
        if ($field === 'field_hls_assets') {
          return $hlsAssetsFieldItemList;
        }
        return $this->createMock(FieldItemListInterface::class);
      });

    $this->fileSystem->method('dirname')
      ->willReturnCallback(function ($uri) use ($baseDir, $wrongDir) {
        if (str_contains($uri, '2024-01-15')) {
          return $baseDir;
        }
        return $wrongDir;
      });

    $this->fileSystem->method('realpath')
      ->willReturnCallback(function ($uri) {
        $filename = basename($uri);
        return $this->fixturesPath . '/' . $filename;
      });

    $this->fileSystem->method('basename')
      ->willReturnCallback(fn($uri) => basename($uri));

    // Directory preparation fails.
    $this->fileSystem->method('prepareDirectory')->willReturn(FALSE);

    $this->database->method('escapeLike')
      ->willReturnCallback(fn($s) => $s);

    $filenameToFid = [
      'segment0.ts' => 1,
      'segment1.ts' => 2,
    ];

    $this->database->method('query')
      ->willReturnCallback(function ($query, $args) use ($filenameToFid) {
        $pattern = $args[':pattern'];
        $filename = substr($pattern, 2);
        $fid = $filenameToFid[$filename] ?? FALSE;

        $statement = $this->createMock(StatementInterface::class);
        $statement->method('fetchField')->willReturn($fid);
        return $statement;
      });

    $fileStorage = $this->createMock(EntityStorageInterface::class);
    $fileStorage->method('load')
      ->willReturnCallback(function ($fid) use ($wrongDir) {
        $file = $this->createMock(FileInterface::class);
        $file->method('id')->willReturn($fid);
        $file->method('getFileUri')->willReturn($wrongDir . '/segment' . ($fid - 1) . '.ts');
        return $file;
      });

    $this->entityTypeManager->method('getStorage')
      ->with('file')
      ->willReturn($fileStorage);

    // Expect error logging for directory preparation failure.
    $this->logger->expects($this->exactly(2))
      ->method('error')
      ->with(
        'Failed to prepare directory: @dir',
        ['@dir' => $baseDir]
      );

    $this->hlsManager->presave($media);
  }

}
