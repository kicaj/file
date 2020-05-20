<?php
namespace SlicesCake\File\Model\Behavior;

use Cake\Datasource\EntityInterface;
use Cake\Event\Event;
use Cake\Event\EventInterface;
use Cake\ORM\Behavior;
use Cake\Utility\Text;
use SlicesCale\File\Exception\LibraryException;
use SlicesCale\File\Exception\PathException;
use SlicesCale\File\Exception\ThumbsException;
use Laminas\Diactoros\UploadedFile;
use ArrayObject;
use SlicesCale\File\Exception\AccessibleException;

class FileBehavior extends Behavior
{

    /**
     * Default config.
     *
     * @var array
     */
    public $defaultConfig = [
        'library' => 'gd',
        'types' => [ // Default allowed types.
            'image/bmp',
            'image/gif',
            'image/jpeg',
            'image/jpg',
            'image/pjpeg',
            'image/pjpg',
            'image/png',
            'image/x-png',
            'image/webp',
        ],
        'extensions' => [ // Default allowed extensions.
            'bmp',
            'gif',
            'jpeg',
            'jpg',
            'pjpg',
            'pjpeg',
            'png',
            'webp',
        ],
        'path' => 'files',
        'background' => [255, 255, 255, 127],
        'watermark' => '',
        'thumbs' => [],
    ];

    /**
     * Array of files to upload.
     *
     * @var array
     */
    protected $files = [];

    /**
     * {@inheritdoc}
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        foreach ($this->getConfig() as $file => $fileConfig) {
            $this->_configDelete($file);

            if (!is_array($fileConfig)) {
                $file = $fileConfig;

                $this->setConfig($file, $this->defaultConfig);
            } else {
                $this->setConfig($file, $config[$file] += $this->defaultConfig);
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function beforeMarshal(Event $event, ArrayObject $data, ArrayObject $options)
    {
        $config = $this->getConfig();

        if (!empty($config)) {
            foreach (array_keys($config) as $file) {
                if (isset($data[$file]) && !empty($data[$file]->getClientFilename())) {
                    $this->setFile($file, $data[$file]);

                    $data[$file] = $this->createName($data[$file]->getClientFilename());
                } else {
                    unset($data[$file]);
                }
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function afterSave(EventInterface $event, EntityInterface $entity, ArrayObject $options)
    {
        $files = $this->getFiles();

        if (!empty($files)) {
            foreach ($files as $file => $fileObject) {
                if ($entity->isAccessible($file)) {
                    if ($fileObject->getError() === 0) {
                        $fileConfig = $this->getConfig($file);

                        // Move original file
                        $fileObject->moveTo($this->getPath($fileConfig['path']) . DS . $entity->{$file});

                        // Prepare thumb files
                        if (!empty($fileConfig['thumbs'])) {
                            $this->createThumbs($entity->{$file}, $fileConfig);
                        }
                    }
                } else {
                    throw new AccessibleException(__d('file', 'Field {0} should be accessible.', $file));
                }
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function beforeDelete(Event $event, EntityInterface $entity, ArrayObject $options)
    {
        $entity = $this->getTable()->find()->select(
            array_merge(
                [$this->getTable()->getPrimaryKey()],
                array_keys($this->getConfig())
            )
        )->where([
            $this->getTable()->getAlias() . '.' . $this->getTable()->getPrimaryKey() => $entity->{$this->getTable()->getPrimaryKey()},
        ])->first();

        return $this->deleteFiles($entity);
    }

    /**
     * {@inheritDoc}
     */
    public function afterDelete(Event $event, EntityInterface $entity, ArrayObject $options)
    {
        return $this->deleteFiles($entity);
    }

    /**
     * Create thumbs.
     *
     * @param string $file File name.
     * @param array $fileConfig File config.
     */
    protected function createThumbs(string $file, array $fileConfig): void
    {
        $filePath = $fileConfig['path'] . DS . $file;

        if (is_file($filePath)) {
            // Check installed image library
            if (!extension_loaded($fileConfig['library'])) {
                throw new LibraryException(__d('file', 'The library identified by {0} is not loaded!', $fileConfig['library']));
            }

            // Get extension from original file
            $fileExtension = $this->getExtension($file);

            $fileConfig['library'] = mb_strtolower($fileConfig['library']);

            switch ($fileConfig['library']) {
                // Get image resource
                case 'gd':
                    switch ($fileExtension) {
                        case 'bmp':
                            $sourceImage = imagecreatefrombmp($filePath);

                            break;
                        case 'gif':
                            $sourceImage = imagecreatefromgif($filePath);

                            break;
                        case 'png':
                            $sourceImage = imagecreatefrompng($filePath);

                            break;
                        case 'webp':
                            $sourceImage = imagecreatefromwebp($filePath);

                            break;
                        default:
                            ini_set('gd.jpeg_ignore_warning', 1);

                            $sourceImage = imagecreatefromjpeg($filePath);

                            break;
                    }

                    // Get original width and height
                    $originalWidth = imagesx($sourceImage);
                    $originalHeight = imagesy($sourceImage);

                    break;
                case 'imagick':
                    $sourceImage = new \Imagick($originalFile);

                    // Get original width and height
                    $originalWidth = $sourceImage->getimagewidth();
                    $originalHeight = $sourceImage->getimageheight();

                    break;
                default:
                    throw new LibraryException(__d('file', 'The library identified by {0} it is not known as image processing!', $fileConfig['library']));
            }

            $offsetX = 0;
            $offsetY = 0;

            $cropX = 0;
            $cropY = 0;

            foreach ($fileConfig['thumbs'] as $thumbName => $thumbConfig) {
                if (isset($thumbConfig['width'])) {
                    list($newWidth, $newHeight) = $this->getDimensionsByNewWidth($originalWidth, $originalHeight, $thumbConfig['width']);
                } elseif (isset($thumbConfig['height'])) {
                    list($newWidth, $newHeight) = $this->getDimensionsByNewHeight($originalWidth, $originalHeight, $thumbConfig['height']);
                } elseif (isset($thumbConfig['shorter']) && is_array($thumbConfig['shorter']) && count($thumbConfig['shorter']) === 2) {
                    list($newWidth, $newHeight) = $this->getDimensionsByShorterSide($originalWidth, $originalHeight, $thumbConfig['shorter'][0], $thumbConfig['shorter'][1]);
                } elseif (isset($thumbConfig['longer']) && is_array($thumbConfig['longer']) && count($thumbConfig['longer']) === 2) {
                    list($newWidth, $newHeight) = $this->getDimensionsByLongerSide($originalWidth, $originalHeight, $thumbConfig['longer'][0], $thumbConfig['longer'][1]);
                } elseif (isset($thumbConfig['fit']) && is_array($thumbConfig['fit']) && count($thumbConfig['fit']) === 2) {
                    list($newWidth, $newHeight, $offsetX, $offsetY, $cropX, $cropY) = $this->getDimensionsByFit($originalWidth, $originalHeight, $thumbConfig['fit'][0], $thumbConfig['fit'][1]);
                } elseif (isset($thumbConfig['fit']) && is_array($thumbConfig['fit']) && count($thumbConfig['fit']) === 3) {
                    list($newWidth, $newHeight, $offsetX, $offsetY, $cropX, $cropY) = $this->getDimensionsByFit($originalWidth, $originalHeight, $thumbConfig['fit'][0], $thumbConfig['fit'][1], $thumbConfig['fit'][2]);
                } elseif (isset($thumbConfig['square']) && is_array($thumbConfig['square']) && count($thumbConfig['square']) === 1) {
                    list($newWidth, $newHeight, $offsetX, $offsetY, $cropX, $cropY) = $this->getDimensionsByFitToSquare($originalWidth, $originalHeight, $thumbConfig['square'][0]);
                } elseif (isset($thumbConfig['square']) && is_array($thumbConfig['square']) && count($thumbConfig['square']) === 2) {
                    list($newWidth, $newHeight, $offsetX, $offsetY, $cropX, $cropY) = $this->getDimensionsByFitToSquare($originalWidth, $originalHeight, $thumbConfig['square'][0], $thumbConfig['square'][1]);
                } else {
                    throw new ThumbsException(__d('file', 'Unknown type or incorrect parameters of creating thumbnails!'));
                }

                $thumbFile = str_replace('default', $thumbName, $filePath);

                switch ($fileConfig['library']) {
                    // Get image resource
                    case 'gd':
                        $newImage = imagecreatetruecolor($newWidth, $newHeight);

                        if (is_array($fileConfig['background'])) {
                            // Set background color and transparent indicates
                            imagefill($newImage, 0, 0, imagecolorallocatealpha($newImage, $fileConfig['background'][0], $fileConfig['background'][1], $fileConfig['background'][2], $fileConfig['background'][3]));
                        }

                        imagecopyresampled($newImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);

                        if ((isset($thumbConfig['square']) && is_array($thumbConfig['square'])) || (isset($thumbConfig['fit']) && is_array($thumbConfig['fit']))) {
                            $fitImage = imagecreatetruecolor($newWidth + (2 * $offsetX) - (2 * $cropX), $newHeight + (2 * $offsetY) - (2 * $cropY));

                            if (is_array($fileConfig['background'])) {
                                // Set background color and transparent indicates
                                imagefill($fitImage, 0, 0, imagecolorallocatealpha($fitImage, $fileConfig['background'][0], $fileConfig['background'][1], $fileConfig['background'][2], $fileConfig['background'][3]));
                            }

                            imagecopyresampled($fitImage, $newImage, $offsetX, $offsetY, $cropX, $cropY, $newWidth, $newHeight, $newWidth, $newHeight);

                            $newImage = $fitImage;
                        }

                        imagealphablending($newImage, false);
                        imagesavealpha($newImage, true);

                        // Watermark
                        if (isset($thumbConfig['watermark']) && ($watermarkSource = file_get_contents($fileConfig['watermark'])) !== false) {
                            $watermarkImage = imagecreatefromstring($watermarkSource);

                            list($watermarkPositionX, $watermarkPositionY) = $this->getPosition(imagesx($newImage), imagesy($newImage), imagesx($watermarkImage), imagesy($watermarkImage), $offsetX, $offsetY, $thumbConfig['watermark']);

                            // Set transparent
                            imagealphablending($newImage, true);
                            imagecopy($newImage, $watermarkImage, $watermarkPositionX, $watermarkPositionY, 0, 0, imagesx($watermarkImage), imagesy($watermarkImage));
                        }

                        // Set resource file type
                        switch ($fileExtension) {
                            case 'bmp':
                                imagebmp($newImage, $thumbFile);

                                break;
                            case 'gif':
                                imagegif($newImage, $thumbFile);

                                break;
                            case 'png':
                                imagepng($newImage, $thumbFile);

                                break;
                            case 'webp':
                                imagewebp($newImage, $thumbFile);

                                break;
                            default:
                                imagejpeg($newImage, $thumbFile, 100);

                                break;
                        }

                        break;
                    case 'imagick':
                        $newImage = $sourceImage->clone();

                        $newImage->scaleimage($newWidth, $newHeight);
                        $newImage->setimagebackgroundcolor('transparent');
                        $newImage->extentimage($newWidth + (2 * $offsetX), $newHeight + (2 * $offsetY), -$offsetX, -$offsetY);

                        if ((isset($thumbConfig['square']) && is_array($thumbConfig['square'])) || (isset($thumbConfig['fit']) && is_array($thumbConfig['fit']))) {
                            $newImage->cropimage($newWidth + (2 * $offsetX) - (2 * $cropX), $newHeight + (2 * $offsetY) - (2 * $cropY), $cropX, $cropY);
                        }

                        // Watermark
                        if (isset($thumbConfig['watermark']) && ($watermarkSource = file_get_contents($fileConfig['watermark'])) !== false) {
                            $watermarkImage = new \Imagick();
                            $watermarkImage->readimageblob($watermarkSource);

                            list($watermarkPositionX, $watermarkPositionY) = $this->getPosition($newWidth, $newHeight, $watermarkImage->getimagewidth(), $watermarkImage->getimageheight(), $offsetX, $offsetY, $thumbConfig['watermark']);

                            $newImage->compositeimage($watermarkImage, \Imagick::COMPOSITE_OVER, $watermarkPositionX, $watermarkPositionY);
                        }

                        // Set object file type
                        $newImage->setImageFormat($fileExtension);

                        $newImage->writeimage($thumbFile);
                        $newImage->clear();

                        break;
                }
            }
        }
    }

    /**
     * Delete files with created thumbs.
     *
     * @param EntityInterface $entity Entity
     * @return boolean True if is successful.
     */
    protected function deleteFiles(EntityInterface $entity)
    {
        $config = $this->getConfig();

        if (!empty($config)) {
            foreach ($config as $file => $fileConfig) {
                if (isset($entity[$file])) {
                    $path = $fileConfig['path'] . DS . substr($entity[$file], 0, 37);

                    foreach (glob($path . '*') as $file) {
                        if (file_exists($file)) {
                            unlink($file);
                        }
                    }
                }
            }
        }

        return true;
    }

    /**
     * Get files fields with config.
     *
     * @return array Files fields with config.
     */
    protected function getFiles(): array
    {
        return $this->files;
    }

    /**
     * Set file fields config.
     *
     * @param string $file File name.
     * @param UploadedFile $data Uploaded data.
     */
    protected function setFile(string $file, UploadedFile $data): void
    {
        $this->files[$file] = $data;
    }

    /**
     * Get file path.
     *
     * @param string $path Path.
     * @return string Path.
     */
    protected function getPath(string $path): string
    {
        if (!is_dir($path)) {
            $this->setPath($path);
        }

        return $path;
    }

    /**
     * Set file path.
     *
     * @param string $path Path.
     * @return string Path.
     */
    protected function setPath(string $path): string
    {
        if (mkdir($path, 0777, true)) {
            return $path;
        }

        throw new PathException(__d('file', 'Could not set path'));
    }

    /**
     * Create file name.
     *
     * @param string $name Uploaded file name.
     * @return string Unique file name.
     */
    protected function createName(string $name): string
    {
        return Text::uuid() . '_default.' . $this->getExtension($name);
    }

    /**
     * Get extension from file name.
     *
     * @param string $name File name.
     * @return string Extension of uploaded file.
     */
    protected function getExtension(string $name): string
    {
        $fileExtension = pathinfo(mb_strtolower($name), PATHINFO_EXTENSION);

        switch ($fileExtension) {
            case 'jpg':
            case 'jpeg':
            case 'pjpg':
            case 'pjpeg':
                // Standarize JPEG image file extension
                return 'jpg';

                break;
            default:
                return $fileExtension;

                break;
        }
    }

    /**
     * Get position of watermark image.
     *
     * @param integer $newWidth New width of uploaded image.
     * @param integer $newHeight New height of uploaded image.
     * @param integer $watermarkWidth Original width of watermark image.
     * @param integer $watermarkHeight Original height of watermark image.
     * @param integer $offsetX Horizontal offset.
     * @param integer $offsetY Vertical offset.
     * @param integer $positionValue Value for position watermark, value between 1 and 9.
     * @return array Coordinates of position watermark.
     */
    protected function getPosition(int $newWidth, int $newHeight, int $watermarkWidth, int $watermarkHeight, int $offsetX = 0, int $offsetY = 0, int $positionValue = 1): array
    {
        switch ($positionValue) {
            case 1: // Top left
                return [$offsetX, $offsetY];

                break;
            case 2: // Top center
                return [($newWidth / 2) - ($watermarkWidth / 2), $offsetY];

                break;
            case 3: // Top right
                return [($newWidth - $watermarkWidth - $offsetX), $offsetY];

                break;
            case 4: // Middle left
                return [$offsetX, intval(($newHeight / 2) - ($watermarkHeight / 2))];

                break;
            case 5: // Middle center
                return [intval(($newWidth / 2) - ($watermarkWidth / 2)), intval(($newHeight / 2) - ($watermarkHeight / 2))];

                break;
            case 6: // Middle right
                return [($newWidth - $watermarkWidth) - $offsetX, intval(($newHeight / 2) - ($watermarkHeight / 2))];

                break;
            case 7: // Bottom left
                return [$offsetX, ($newHeight - $watermarkHeight) - $offsetY];

                break;
            case 8: // Bottom center
                return [intval(($newWidth / 2) - ($watermarkWidth / 2)), ($newHeight - $watermarkHeight) - $offsetY];

                break;
            case 9: // Bottom right
                return [($newWidth - $watermarkWidth) - $offsetX, ($newHeight - $watermarkHeight) - $offsetY];

                break;
            default:
                return [$offsetX, $offsetY];

                break;
        }
    }

    /**
     * Get dimension by new width.
     *
     * @param integer $originalWidth Original width of uploaded image.
     * @param integer $originalHeight Original height of uploaded image.
     * @param integer $newWidth Set new image width.
     * @return array New width and height.
     */
    public static function getDimensionsByNewWidth(int $originalWidth, int $originalHeight, int $newWidth): array
    {
        if ($newWidth > $originalWidth) {
            $newWidth = $originalWidth;
            $newHeight = $originalHeight;
        } else {
            $newHeight = intval($newWidth * ($originalHeight / $originalWidth));
        }

        return [$newWidth, $newHeight];
    }

    /**
     * Get dimension by new height.
     *
     * @param integer $originalWidth Original width of uploaded image.
     * @param integer $originalHeight Original height of uploaded image.
     * @param integer $newHeight Set new image height.
     * @return array New width and height.
     */
    public static function getDimensionsByNewHeight(int $originalWidth, int $originalHeight, int $newHeight): array
    {
        if ($newHeight > $originalHeight) {
            $newHeight = $originalHeight;
            $newWidth = $originalWidth;
        } else {
            $newWidth = intval($newHeight * ($originalWidth / $originalHeight));
        }

        return [$newWidth, $newHeight];
    }

    /**
     * Get dimension by shorter side.
     *
     * @param integer $originalWidth Original width of uploaded image.
     * @param integer $originalHeight Original height of uploaded image.
     * @param integer $newWidth Set new image min width.
     * @param integer $newHeight Set new image min height.
     * @return array New width and height.
     */
    public static function getDimensionsByShorterSide(int $originalWidth, int $originalHeight, int $newWidth, int $newHeight): array
    {
        if ($originalWidth < $originalHeight) {
            list($newWidth, $newHeight) = self::getDimensionsByNewWidth($originalWidth, $originalHeight, $newWidth);
        } else {
            list($newWidth, $newHeight) = self::getDimensionsByNewHeight($originalWidth, $originalHeight, $newHeight);
        }

        return [$newWidth, $newHeight];
    }

    /**
     * Get dimension by longer side.
     *
     * @param integer $originalWidth Original width of uploaded image.
     * @param integer $originalHeight Original height of uploaded image.
     * @param integer $newWidth Set new image max width.
     * @param integer $newHeight Set new image max height.
     * @return array New width and height.
     */
    public static function getDimensionsByLongerSide(int $originalWidth, int $originalHeight, int $newWidth, int $newHeight): array
    {
        if ($originalWidth > $originalHeight) {
            list($newWidth, $newHeight) = self::getDimensionsByNewWidth($originalWidth, $originalHeight, $newWidth);
        } else {
            list($newWidth, $newHeight) = self::getDimensionsByNewHeight($originalWidth, $originalHeight, $newHeight);
        }

        return [$newWidth, $newHeight];
    }

    /**
     * Get dimension by fit.
     *
     * @param integer $originalWidth Original width of uploaded image.
     * @param integer $originalHeight Original height of uploaded image.
     * @param integer $newWidth Set new image width.
     * @param integer $newHeight Set new image height.
     * @param boolean $originalKeep Save original shape.
     * @return array New width and height and offsets of position with keeping original shape.
     */
    public static function getDimensionsByFit(int $originalWidth, int $originalHeight, int $newWidth, int $newHeight, bool $originalKeep = false): array
    {
        $offsetX = 0;
        $offsetY = 0;
        $cropX = 0;
        $cropY = 0;

        if ($originalKeep === true) {
            if ($originalWidth == $originalHeight) {
                $newSizes = self::getDimensionsByLongerSide($originalWidth, $originalHeight, min($newWidth, $newHeight), min($newWidth, $newHeight));
            } else {
                $newSizes = self::getDimensionsByLongerSide($originalWidth, $originalHeight, $newWidth, $newHeight);

                if ($newWidth < $newSizes[0] || $newHeight < $newSizes[1]) {
                    $newSizes = self::getDimensionsByShorterSide($originalWidth, $originalHeight, $newWidth, $newHeight);
                }
            }
        } else {
            if ($originalWidth == $originalHeight) {
                $newSizes = self::getDimensionsByShorterSide($originalWidth, $originalHeight, max($newWidth, $newHeight), max($newWidth, $newHeight));
            } else {
                $newSizes = self::getDimensionsByShorterSide($originalWidth, $originalHeight, $newWidth, $newHeight);

                if ($newWidth > $newSizes[0] || $newHeight > $newSizes[1]) {
                    $newSizes = self::getDimensionsByLongerSide($originalWidth, $originalHeight, $newWidth, $newHeight);
                }
            }
        }

        if ($newWidth < $newSizes[0]) {
            $cropX = ($newSizes[0] - $newWidth) / 2;
        } else {
            $offsetX = ($newWidth - $newSizes[0]) / 2;
        }

        if ($newHeight < $newSizes[1]) {
            $cropY = ($newSizes[1] - $newHeight) / 2;
        } else {
            $offsetY = ($newHeight - $newSizes[1]) / 2;
        }

        return [$newSizes[0], $newSizes[1], intval($offsetX), intval($offsetY), intval($cropX), intval($cropY)];
    }

    /**
     * Get dimension to square.
     *
     * @param integer $originalWidth Original width of uploaded image.
     * @param integer $originalHeight Original height of uploaded image.
     * @param integer $newSide Set new image side.
     * @param boolean $originalKeep Save original shape.
     * @return array New width and height with coordinates of crop or offsets of position.
     */
    public static function getDimensionsByFitToSquare(int $originalWidth, int $originalHeight, int $newSide, bool $originalKeep = false): array
    {
        $offsetX = 0;
        $offsetY = 0;
        $cropX = 0;
        $cropY = 0;

        if ($originalKeep === true) {
            list($newWidth, $newHeight) = self::getDimensionsByLongerSide($originalWidth, $originalHeight, $newSide, $newSide);

            if ($newSide > $newWidth) {
                $offsetX = ($newSide - $newWidth) / 2;
            }

            if ($newSide > $newHeight) {
                $offsetY = ($newSide - $newHeight) / 2;
            }
        } else {
            list($newWidth, $newHeight) = self::getDimensionsByShorterSide($originalWidth, $originalHeight, $newSide, $newSide);

            if ($newSide < $newWidth) {
                $cropX = ($newWidth - $newSide) / 2;
            } else {
                $offsetX = ($newSide - $newWidth) / 2;
            }

            if ($newSide < $newHeight) {
                $cropY = ($newHeight - $newSide) / 2;
            } else {
                $offsetY = ($newSide - $newHeight) / 2;
            }
        }

        return [$newWidth, $newHeight, intval($offsetX), intval($offsetY), intval($cropX), intval($cropY)];
    }
}