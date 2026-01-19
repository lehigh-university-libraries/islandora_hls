<?php

namespace Drupal\islandora_hls\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\FileInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'hls_player' formatter.
 *
 * @FieldFormatter(
 *   id = "hls_player",
 *   label = @Translation("HLS Player"),
 *   field_types = {
 *     "file"
 *   }
 * )
 */
class HlsFormatter extends FormatterBase {

  /**
   * The file URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected FileUrlGeneratorInterface $fileUrlGenerator;

  /**
   * Constructs an HlsFormatter object.
   *
   * @param string $plugin_id
   *   The plugin_id for the formatter.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The definition of the field to which the formatter is associated.
   * @param array $settings
   *   The formatter settings.
   * @param string $label
   *   The formatter label display setting.
   * @param string $view_mode
   *   The view mode.
   * @param array $third_party_settings
   *   Any third party settings.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $file_url_generator
   *   The file URL generator.
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, $label, $view_mode, array $third_party_settings, FileUrlGeneratorInterface $file_url_generator) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);
    $this->fileUrlGenerator = $file_url_generator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('file_url_generator')
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'media_type' => 'auto',
      'width' => '100%',
      'height' => 'auto',
      'controls' => TRUE,
      'autoplay' => FALSE,
      'muted' => FALSE,
      'loop' => FALSE,
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements = parent::settingsForm($form, $form_state);

    $elements['media_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Player type'),
      '#default_value' => $this->getSetting('media_type'),
      '#options' => [
        'auto' => $this->t('Auto-detect'),
        'video' => $this->t('Video'),
        'audio' => $this->t('Audio'),
      ],
      '#description' => $this->t('Select "Video" for waveform visualizations or other audio-to-video conversions.'),
    ];

    $elements['width'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Width'),
      '#default_value' => $this->getSetting('width'),
      '#description' => $this->t('The width of the video player (e.g., 100%, 640px).'),
    ];

    $elements['height'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Height'),
      '#default_value' => $this->getSetting('height'),
      '#description' => $this->t('The height of the video player (e.g., auto, 360px).'),
    ];

    $elements['controls'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show controls'),
      '#default_value' => $this->getSetting('controls'),
    ];

    $elements['autoplay'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Autoplay'),
      '#default_value' => $this->getSetting('autoplay'),
      '#description' => $this->t('Note: Most browsers block autoplay unless the video is muted.'),
    ];

    $elements['muted'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Muted'),
      '#default_value' => $this->getSetting('muted'),
    ];

    $elements['loop'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Loop'),
      '#default_value' => $this->getSetting('loop'),
    ];

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];

    $media_type = $this->getSetting('media_type');
    $type_labels = [
      'auto' => $this->t('Auto-detect'),
      'video' => $this->t('Video'),
      'audio' => $this->t('Audio'),
    ];
    $summary[] = $this->t('Player type: @type', ['@type' => $type_labels[$media_type] ?? $media_type]);
    $summary[] = $this->t('Width: @width', ['@width' => $this->getSetting('width')]);
    $summary[] = $this->t('Height: @height', ['@height' => $this->getSetting('height')]);

    $options = [];
    if ($this->getSetting('controls')) {
      $options[] = $this->t('controls');
    }
    if ($this->getSetting('autoplay')) {
      $options[] = $this->t('autoplay');
    }
    if ($this->getSetting('muted')) {
      $options[] = $this->t('muted');
    }
    if ($this->getSetting('loop')) {
      $options[] = $this->t('loop');
    }

    if (!empty($options)) {
      $summary[] = $this->t('Options: @options', ['@options' => implode(', ', $options)]);
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];

    foreach ($items as $delta => $item) {
      $file = $item->entity;
      if (!$file instanceof FileInterface) {
        continue;
      }

      $uri = $file->getFileUri();
      $extension = pathinfo($uri, PATHINFO_EXTENSION);

      // Only process m3u8 files.
      if ($extension !== 'm3u8') {
        continue;
      }

      $url = $this->fileUrlGenerator->generateAbsoluteString($uri);

      $elements[$delta] = [
        '#theme' => 'hls_player',
        '#url' => $url,
        '#media_type' => $this->determineMediaType($items),
        '#width' => $this->getSetting('width'),
        '#height' => $this->getSetting('height'),
        '#controls' => $this->getSetting('controls'),
        '#autoplay' => $this->getSetting('autoplay'),
        '#muted' => $this->getSetting('muted'),
        '#loop' => $this->getSetting('loop'),
        '#attached' => [
          'library' => ['islandora_hls/hls_player'],
        ],
      ];
    }

    return $elements;
  }

  /**
   * Determines the media type based on settings and entity.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The field items.
   *
   * @return string
   *   The media type ('video' or 'audio').
   */
  protected function determineMediaType(FieldItemListInterface $items) {
    $setting = $this->getSetting('media_type');

    // If explicitly set, use that.
    if ($setting === 'video' || $setting === 'audio') {
      return $setting;
    }

    // Auto-detect based on entity bundle.
    $entity = $items->getEntity();
    if ($entity->getEntityTypeId() === 'media') {
      $bundle = $entity->bundle();
      if ($bundle === 'audio') {
        return 'audio';
      }
    }

    // Default to video.
    return 'video';
  }

}
