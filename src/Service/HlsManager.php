<?php

namespace Drupal\islandora_hls\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Service to manage HLS files.
 */
class HlsManager {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * The file system.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected FileSystemInterface $fileSystem;

  /**
   * The file repository.
   *
   * @var \Drupal\file\FileRepositoryInterface
   */
  protected FileRepositoryInterface $fileRepository;

  /**
   * HlsManager constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system.
   * @param \Drupal\file\FileRepositoryInterface $file_repository
   *   The file repository.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, LoggerInterface $logger, FileSystemInterface $file_system, FileRepositoryInterface $file_repository) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger;
    $this->fileSystem = $file_system;
    $this->fileRepository = $file_repository;
  }

  /**
   * Presave method for media.
   *
   * @param \Drupal\Core\Entity\EntityInterface $media
   *   The media entity.
   */
  public function presave(EntityInterface $media): void {
    // Only process audio or video media.
    if (!in_array($media->bundle(), ['audio', 'video'])) {
      return;
    }

    if (!$media->hasField('field_hls_assets')) {
      return;
    }

    $fieldName = 'field_media_' . $media->bundle() . '_file';
    if (!$media->hasField($fieldName) || $media->get($fieldName)->isEmpty()) {
      return;
    }

    $file_entity = $media->get($fieldName)->entity;
    if (!$file_entity instanceof FileInterface) {
      return;
    }

    // Only process m3u8 files.
    $file_uri = $file_entity->getFileUri();
    if (pathinfo($file_uri, PATHINFO_EXTENSION) !== 'm3u8') {
      return;
    }

    $baseDir = $this->fileSystem->dirname($file_uri);

    // Parse the master m3u8 to find all referenced files.
    $referencedFiles = $this->parseM3u8ForAssets($file_uri, $baseDir);

    if (empty($referencedFiles)) {
      return;
    }

    // Look up files by filename and ensure they're in the correct directory.
    // Edge case: if HLS derivative processing begins one day and ends the next,
    // files may end up in different dated directories. We normalize by moving
    // all assets to the same directory as the master m3u8.
    $newAssetFids = [];
    foreach ($referencedFiles as $filename) {
      $file = $this->getFileByFilename($filename);
      if ($file === NULL) {
        $this->logger->error('HLS asset file not found in database: @filename', [
          '@filename' => $filename,
        ]);
        continue;
      }

      // Move file to correct directory if needed.
      $file = $this->ensureFileInDirectory($file, $baseDir);
      if ($file === NULL) {
        continue;
      }

      $newAssetFids[$file->id()] = $file->id();
    }

    // Get existing field_hls_assets fids.
    $existingFids = [];
    foreach ($media->get('field_hls_assets')->getValue() as $item) {
      $existingFids[$item['target_id']] = $item['target_id'];
    }

    // Delete files that are no longer referenced.
    $fidsToDelete = array_diff_key($existingFids, $newAssetFids);
    foreach ($fidsToDelete as $fid) {
      $this->deleteFile($fid);
    }

    // Set the new field_hls_assets value.
    $newValue = [];
    foreach ($newAssetFids as $fid) {
      $newValue[] = ['target_id' => $fid];
    }
    $media->set('field_hls_assets', $newValue);
  }

  /**
   * Parses an m3u8 file and returns all referenced asset filenames.
   *
   * @param string $m3u8Uri
   *   The URI of the m3u8 file to parse.
   * @param string $baseDir
   *   The base directory for resolving relative paths to variant playlists.
   *
   * @return array
   *   An array of filenames for all referenced .m3u8 and .ts files.
   */
  protected function parseM3u8ForAssets(string $m3u8Uri, string $baseDir): array {
    $realPath = $this->fileSystem->realpath($m3u8Uri);
    if (!$realPath || !file_exists($realPath)) {
      $this->logger->error('Cannot read m3u8 file: @uri', ['@uri' => $m3u8Uri]);
      return [];
    }

    $contents = file_get_contents($realPath);
    if ($contents === FALSE) {
      $this->logger->error('Failed to read m3u8 file contents: @uri', ['@uri' => $m3u8Uri]);
      return [];
    }

    $assets = [];
    $lines = explode("\n", $contents);

    foreach ($lines as $line) {
      $line = trim($line);

      // Skip empty lines and comments/directives.
      if (empty($line) || str_starts_with($line, '#')) {
        continue;
      }

      // This is a file reference (either .m3u8 or .ts).
      $extension = pathinfo($line, PATHINFO_EXTENSION);

      if ($extension === 'm3u8') {
        // Variant playlist - add filename and recursively parse for .ts files.
        $assets[] = $line;
        $variantUri = $baseDir . '/' . $line;
        $assets = array_merge($assets, $this->parseM3u8ForAssets($variantUri, $baseDir));
      }
      elseif ($extension === 'ts') {
        // Segment file - just the filename.
        $assets[] = $line;
      }
    }

    return $assets;
  }

  /**
   * Gets a file entity by its filename.
   *
   * @param string $filename
   *   The filename to search for.
   *
   * @return \Drupal\file\FileInterface|null
   *   The file entity, or NULL if not found.
   */
  protected function getFileByFilename(string $filename): ?FileInterface {
    $files = $this->fileRepository->loadByProperties(['filename' => $filename]);
    if (empty($files)) {
      return NULL;
    }

    return reset($files);
  }

  /**
   * Ensures a file is in the specified directory, moving it if necessary.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file entity.
   * @param string $targetDir
   *   The target directory URI.
   *
   * @return \Drupal\file\FileInterface|null
   *   The file entity (possibly updated), or NULL on failure.
   */
  protected function ensureFileInDirectory(FileInterface $file, string $targetDir): ?FileInterface {
    $currentUri = $file->getFileUri();
    $currentDir = $this->fileSystem->dirname($currentUri);

    // File is already in the correct directory.
    if ($currentDir === $targetDir) {
      return $file;
    }

    // Move file to target directory.
    $filename = $this->fileSystem->basename($currentUri);
    $newUri = $targetDir . '/' . $filename;

    try {
      // Ensure target directory exists.
      if (!$this->fileSystem->prepareDirectory($targetDir, FileSystemInterface::CREATE_DIRECTORY)) {
        $this->logger->error('Failed to prepare directory: @dir', ['@dir' => $targetDir]);
        return NULL;
      }

      $movedUri = $this->fileSystem->move($currentUri, $newUri, FileSystemInterface::EXISTS_REPLACE);
      $file->setFileUri($movedUri);
      $file->save();

      return $file;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to move file from @source to @dest: @error', [
        '@source' => $currentUri,
        '@dest' => $newUri,
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Deletes a file entity and its physical file.
   *
   * @param int $fid
   *   The file ID to delete.
   */
  protected function deleteFile($fid): void {
    $file = $this->entityTypeManager->getStorage('file')->load($fid);
    if ($file instanceof FileInterface) {
      try {
        $file->delete();
      }
      catch (\Exception $e) {
        $this->logger->warning('Failed to delete file @fid: @error', [
          '@fid' => $fid,
          '@error' => $e->getMessage(),
        ]);
      }
    }
  }

}
