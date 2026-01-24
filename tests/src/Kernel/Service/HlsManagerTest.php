<?php

declare(strict_types=1);

namespace Drupal\Tests\islandora_hls\Kernel\Service;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\file\Entity\File;
use Drupal\islandora_hls\Service\HlsManager;
use Drupal\media\Entity\Media;
use Drupal\Tests\media\Kernel\MediaKernelTestBase;
use Psr\Log\LoggerInterface;

/**
 * Tests the HlsManager service.
 *
 * @group islandora_hls
 * @coversDefaultClass \Drupal\islandora_hls\Service\HlsManager
 */
class HlsManagerTest extends MediaKernelTestBase {

  /**
   * The HlsManager service.
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
   * Test files.
   *
   * @var array
   */
  protected array $files = [];

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected LoggerInterface $logger;

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'islandora',
    'islandora_hls',
    'islandora_core_feature',
    'image',
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);
    // Replace the logger with a mock so we can check its output.
    $this->logger = $this->createMock(LoggerInterface::class);
    $container->set('islandora_hls.logger', $this->logger);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['islandora_hls', 'islandora_core_feature']);

    $this->fixturesPath = dirname(__DIR__, 4) . '/fixtures';
    $this->hlsManager = $this->container->get('islandora_hls.hls_manager');

    // Create a directory to store the files.
    $this->fileSystem->prepareDirectory('public://hls', FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    // Create a directory for files to be moved from.
    $this->fileSystem->prepareDirectory('public://hls_wrong', FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    // Create file entities for all fixtures.
    foreach (glob($this->fixturesPath . '/*') as $fixture) {
      $filename = basename($fixture);
      $contents = file_get_contents($fixture);
      $file = \Drupal::service('file.repository')->writeData($contents, 'public://hls/' . $filename);
      $this->files[$filename] = $file;
    }
  }

  /**
   * Tests that presave skips non-audio/video media bundles.
   *
   * @covers ::presave
   */
  public function testPresaveSkipsNonAudioVideoBundle(): void {
    $media = Media::create([
      'bundle' => 'image',
      'name' => 'Test Image',
    ]);
    $media->save();

    // Should not throw an exception or modify the media.
    $this->hlsManager->presave($media);

    // Verify the media entity is unchanged (image media has no field_hls_assets).
    $this->assertFalse($media->hasField('field_hls_assets'));
  }

  /**
   * Tests that presave skips media without field_hls_assets.
   *
   * @covers ::presave
   */
  public function testPresaveSkipsMediaWithoutHlsAssetsField(): void {
    $media_type = $this->createMediaType('video', ['id' => 'video_no_hls']);
    // Remove the field_hls_assets from this media type.
    $media_type->set('field_map', []);
    $media_type->save();

    $media = Media::create([
      'bundle' => 'video_no_hls',
      'name' => 'Test Video',
    ]);
    $media->save();

    // Should not try to get the file field.
    $this->hlsManager->presave($media);
    $this->assertFalse($media->hasField('field_hls_assets'));
  }

  /**
   * Tests that presave skips media without the media file field.
   *
   * @covers ::presave
   */
  public function testPresaveSkipsMediaWithoutFileField(): void {
    $media = Media::create([
      'bundle' => 'video',
      'name' => 'Test Video',
    ]);
    // Remove the source field from the entity.
    $media->set('field_media_video_file', []);
    $media->save();

    $this->hlsManager->presave($media);
    $this->assertEmpty($media->get('field_hls_assets')->getValue());
  }

  /**
   * Tests that presave skips media with empty file field.
   *
   * @covers ::presave
   */
  public function testPresaveSkipsMediaWithEmptyFileField(): void {
    $media = Media::create([
      'bundle' => 'audio',
      'name' => 'Test Audio',
      'field_media_audio_file' => [],
    ]);
    $media->save();

    $this->hlsManager->presave($media);
    $this->assertEmpty($media->get('field_hls_assets')->getValue());
  }

  /**
   * Tests that presave skips non-m3u8 files.
   *
   * @covers ::presave
   */
  public function testPresaveSkipsNonM3u8Files(): void {
    $file = File::create([
      'uri' => 'public://video.mp4',
      'filename' => 'video.mp4',
    ]);
    $file->save();

    $media = Media::create([
      'bundle' => 'video',
      'name' => 'Test Video',
      'field_media_video_file' => ['target_id' => $file->id()],
    ]);
    $media->save();

    $this->hlsManager->presave($media);
    $this->assertEmpty($media->get('field_hls_assets')->getValue());
  }

  /**
   * Tests parsing a master m3u8 with multiple variant playlists.
   *
   * @covers ::presave
   */
  public function testPresaveParsesMasterPlaylist(): void {
    $media = Media::create([
      'bundle' => 'video',
      'name' => 'Test Video',
      'field_media_video_file' => ['target_id' => $this->files['master.m3u8']->id()],
    ]);
    $media->save();

    $this->hlsManager->presave($media);
    $this->assertCount(12, $media->get('field_hls_assets')->getValue());
  }

  /**
   * Tests that presave logs error for missing files.
   *
   * @covers ::presave
   */
  public function testPresaveLogsMissingFiles(): void {
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

    $media = Media::create([
      'bundle' => 'video',
      'name' => 'Test Video',
      'field_media_video_file' => ['target_id' => $this->files['single_variant.m3u8']->id()],
    ]);
    $media->save();

    // Delete the files so they can't be found.
    $this->files['segment0.ts']->delete();
    $this->files['segment1.ts']->delete();

    $this->hlsManager->presave($media);
    $this->assertEmpty($media->get('field_hls_assets')->getValue());
  }

  /**
   * Tests that presave deletes old files no longer referenced.
   *
   * @covers ::presave
   */
  public function testPresaveDeletesOldFiles(): void {
    $old_file = File::create([
      'uri' => 'public://hls/old_file.ts',
      'filename' => 'old_file.ts',
    ]);
    $old_file->save();

    $media = Media::create([
      'bundle' => 'video',
      'name' => 'Test Video',
      'field_media_video_file' => ['target_id' => $this->files['single_variant.m3u8']->id()],
      'field_hls_assets' => [
        ['target_id' => $this->files['segment0.ts']->id()],
        ['target_id' => $this->files['segment1.ts']->id()],
        ['target_id' => $old_file->id()],
      ],
    ]);
    $media->save();

    $this->hlsManager->presave($media);

    $this->assertCount(2, $media->get('field_hls_assets')->getValue());
    $this->assertNull(File::load($old_file->id()));
  }

  /**
   * Tests that presave moves files to correct directory.
   *
   * @covers ::presave
   */
  public function testPresaveMovesFilesToCorrectDirectory(): void {
    $media = Media::create([
      'bundle' => 'video',
      'name' => 'Test Video',
      'field_media_video_file' => ['target_id' => $this->files['single_variant.m3u8']->id()],
    ]);
    $media->save();

    // Move the files to the wrong directory.
    $this->fileSystem->move($this->files['segment0.ts']->getFileUri(), 'public://hls_wrong/segment0.ts');
    $this->fileSystem->move($this->files['segment1.ts']->getFileUri(), 'public://hls_wrong/segment1.ts');

    $this->hlsManager->presave($media);

    $this->assertCount(2, $media->get('field_hls_assets')->getValue());
    $hls_assets = $media->get('field_hls_assets')->referencedEntities();
    foreach ($hls_assets as $asset) {
      $this->assertStringStartsWith('public://hls/', $asset->getFileUri());
    }
  }

  /**
   * Tests that presave handles audio media bundles.
   *
   * @covers ::presave
   */
  public function testPresaveHandlesAudioBundle(): void {
    $media = Media::create([
      'bundle' => 'audio',
      'name' => 'Test Audio',
      'field_media_audio_file' => ['target_id' => $this->files['single_variant.m3u8']->id()],
    ]);
    $media->save();

    $this->hlsManager->presave($media);

    $this->assertCount(2, $media->get('field_hls_assets')->getValue());
  }

  /**
   * Tests that presave handles unreadable m3u8 file.
   *
   * @covers ::presave
   */
  public function testPresaveHandlesUnreadableM3u8(): void {
    $this->logger->expects($this->once())
      ->method('error')
      ->with('Cannot read m3u8 file: @uri', ['@uri' => 'public://hls/nonexistent.m3u8']);

    // Create a file entity for a nonexistent file.
    $file = File::create([
      'uri' => 'public://hls/nonexistent.m3u8',
      'filename' => 'nonexistent.m3u8',
    ]);
    $file->save();

    $media = Media::create([
      'bundle' => 'video',
      'name' => 'Test Video',
      'field_media_video_file' => ['target_id' => $file->id()],
    ]);
    $media->save();

    $this->hlsManager->presave($media);

    $this->assertEmpty($media->get('field_hls_assets')->getValue());
  }

}
