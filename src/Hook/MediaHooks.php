<?php

declare(strict_types=1);

namespace Drupal\islandora_hls\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\islandora_hls\Service\HlsManager;

/**
 * Hook implementations for media entities.
 */
class MediaHooks {

  /**
   * The HLS manager service.
   *
   * @var \Drupal\islandora_hls\Service\HlsManager
   */
  protected HlsManager $hlsManager;

  /**
   * Constructs a new MediaHooks object.
   *
   * @param \Drupal\islandora_hls\Service\HlsManager $hls_manager
   *   The HLS manager service.
   */
  public function __construct(HlsManager $hls_manager) {
    $this->hlsManager = $hls_manager;
  }

  /**
   * Implements hook_ENTITY_TYPE_presave() for media entities.
   *
   * @param \Drupal\Core\Entity\EntityInterface $media
   *   The media entity being saved.
   */
  #[Hook('media_presave')]
  public function mediaPresave(EntityInterface $media): void {
    $this->hlsManager->presave($media);
  }

}
