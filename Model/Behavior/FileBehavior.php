<?php

/**
 * File Behavior for upload files and processing images.
 *
 * Tested on CakePHP 2.5.2/PHP 5.5.10/GD 2.0.1
 *
 * @todo          Rewrite for use built-in validation (e.g. Validation::extension(); and Validation::mimeType();) and other addition (e.g. CakeNumber::fromReadableSize();) - Q3 2014
 * @todo          Rewrite to PHP 5.5 for new function from GD2 library (e.g. imagescale(); or imagecrop();) - Q4 2014
 * @copyright     Radosław Zając, kicaj (kicaj@kdev.pl)
 * @link          http://repo.kdev.pl/filebehavior Repository
 * @package       Cake.Model.Behavior
 * @version       1.9.20140816
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */

App::uses('ModelBehavior', 'Model');

class FileBehavior extends ModelBehavior {

	/**
	 * Default settings
	 *
	 * @var array
	 */
	protected $_default = array(
		'types' => array( // Default allowed types
			'image/jpeg',
			'image/jpg',
			'image/pjpeg',
			'image/pjpg',
			'image/png',
			'image/x-png',
			'image/gif'
		),
		'extensions' => array( // Default allowed extensions
			'jpeg',
			'jpg',
			'pjpg',
			'pjpeg',
			'png',
			'gif'
		),
		'path' => 'files',
		'background' => array(255, 255, 255, 127),
		'watermark' => '',
		'thumbs' => array()
	);

	/**
	 * Default validations rules
	 *
	 * @var array
	 */
	protected $_validate = array(
		'max' => array(
			'rule' => array('fileSize', '<=', '10M'),
			'message' => 'Niestety, ale maksymalny rozmiar pliku został przekroczony!'
		),
		'type' => array(
			'rule' => array('fileType'),
			'message' => 'Niestety, ale niedozwolony typ pliku!'
		),
		'ext' => array(
			'rule' => array('fileExtension'),
			'message' => 'Niestety, ale niedozwolony typ rozszerzenia pliku!'
		)
	);

	/**
	 * Array of files to upload.
	 *
	 * @var array
	 */
	public $files = array();

	/**
	 * Initiate behavior
	 *
	 * @param Model $model Instance of Model
	 * @param array $config Configuration settings
	 */
	public function setup(Model $model, $config = array()) {
		foreach ($config as $field => $array) {
			// Set validations rules
			$validation = array();

			if (isset($model->validate[$field])) {
				$validation = $model->validate[$field];
			}

			$model->validate[$field] = array_merge($this->_validate, $validation);

			if (is_array($array)) {
				$this->settings[$model->alias][$field] = array_merge($this->_default, $array);
			} else {
				$this->settings[$model->alias][$array] = $this->_default;
			}
		}
	}

	/**
	 * beforeSave callback
	 *
	 * @param Model $model Instance of Model
	 * @param array $options Options passed from model
	 * @return boolean True if it is successful
	 */
	public function beforeSave(Model $model, $options = array()) {
		if (!empty($this->settings[$model->alias])) {
			foreach ($this->settings[$model->alias] as $fieldName => $fieldOptions) {
				// Check for temporary file
				if (isset($model->data[$model->alias][$fieldName]) && !empty($model->data[$model->alias][$fieldName]['name']) && file_exists($model->data[$model->alias][$fieldName]['tmp_name'])) {
					// Settings for files
					$this->files[$fieldName] = $model->data[$model->alias][$fieldName];
					$this->files[$fieldName]['path'] = $this->prepareDir($fieldOptions['path']);
					$this->files[$fieldName]['name'] = $this->prepareName($model, $fieldName);

					$model->data[$model->alias][$fieldName] = $this->files[$fieldName]['name'];
				} else {
					// Delete file array from data when is not attached
					unset($model->data[$model->alias][$fieldName]);
				}
			}
		}

		return true;
	}

	/**
	 * afterSave callback
	 *
	 * @param Model $model Instance of Model
	 * @param boolean $created True if this save created a new record
	 * @param array $options Options passed from Model
	 * @return boolean
	 */
	public function afterSave(Model $model, $created, $options = array()) {
		if ($created !== true && empty($this->files)) {

		} else {
			$this->prepareFile($model);
		}
	}

	/**
	 * beforeDelete callback
	 *
	 * @param Model $model Instance of Model
	 * @param boolean $cascade If true records that depend on this record will also be deleted
	 * @return boolean True if it is successful
	 */
	public function beforeDelete(Model $model, $cascade = true) {
		return $this->deleteFile($model);
	}

	/**
	 * Generate random name of uploaded file.
	 * If action is for update with not used file then it will be removed.
	 *
	 * @todo Prepare method for working without field id
	 * @todo Generate names of files by user method
	 * @todo DRY, use self::deleteFile();
	 * @param Model $model Reference to model
	 * @param string $fieldName Name of field in form
	 * @return string New name of file
	 */
	public function prepareName(Model $model, $fieldName) {
		if (isset($model->id)) {
			$dataField = $model->findById($model->id);

			if (is_array($dataField) && !empty($dataField) && is_file($this->files[$fieldName]['path'] . DS . $dataField[$model->alias][$fieldName])) {
				$filePattern = $this->settings[$model->alias][$fieldName]['path'] . DS . substr($dataField[$model->alias][$fieldName], 0, 14);

				foreach (glob($filePattern . '*') as $fileName) {
					// Remove file
					@unlink($fileName);
				}
			}
		}

		$nameFile = substr(String::uuid(), -27, 14) . '_original.' . $this->getExtension($this->files[$fieldName]['name']);

		return $nameFile;
	}

	/**
	 * Set path to directory for save uploaded files.
	 * If directory isn't exists, will be created with privileges to save and read.
	 *
	 * @param string $dirPath Path to directory
	 * @return string Path to directory
	 */
	public function prepareDir($dirPath) {
		$dirPath = str_replace('/', DS, $dirPath);

		if (!is_dir($dirPath) && strlen($dirPath) > 0) {
			mkdir($dirPath, 0777, true);
		}

		chmod($dirPath, 0777);

		return $dirPath;
	}

	/**
	 * Copy and upload file.
	 * If settings are for image thumbs then create it.
	 *
	 * @param Model $model Reference to model
	 */
	public function prepareFile(Model $model) {
		foreach ($this->files as $fieldName => $fieldOptions) {
			// Name of original file
			$fileName = $fieldOptions['path'] . DS . $this->files[$fieldName]['name'];

			if (move_uploaded_file($this->files[$fieldName]['tmp_name'], $fileName)) {
				if (isset($this->settings[$model->alias][$fieldName]['thumbs'])) {
					$this->prepareThumbs($fileName, $this->settings[$model->alias][$fieldName]);
				}
			}
		}
	}

	/**
	 * Delete file with created thumbs
	 *
	 * @param Model $model Reference to model
	 * @return boolean True if is success
	 */
	public function deleteFile(Model $model) {
		// Get field list of model schema
		$modelSchema = $model->schema();

		foreach ($this->settings[$model->alias] as $fieldName => $fieldOptions) {
			// Check is field in model schema
			if (isset($modelSchema[$fieldName])) {
				$dataField = $model->findById($model->id);

				if (is_array($dataField) && !empty($dataField[$model->alias][$fieldName])) {
					// Pattern for original file with thumbs
					$filePattern = $this->settings[$model->alias][$fieldName]['path'] . DS . substr($dataField[$model->alias][$fieldName], 0, 14);

					foreach (glob($filePattern . '*') as $fileName) {
						// Remove file
						@unlink($fileName);
					}
				}
			}
		}

		return true;
	}

	/**
	 * Generate thumbs by names with parameters
	 *
	 * @param string $originalFile Path to original file
	 * @param array $thumbParams Settings for uploaded files
	 * @return boolean Output image to save file
	 */
	public function prepareThumbs($originalFile, $settingsParams) {
		if (is_file($originalFile) && is_array($settingsParams)) {
			// Get extension from original file
			$fileExtension = $this->getExtension($originalFile);

			// Get image resource
			switch ($fileExtension) {
				case 'jpg':
					ini_set('gd.jpeg_ignore_warning', 1);

					$sourceImage = imagecreatefromjpeg($originalFile);

					break;
				case 'gif':
					$sourceImage = imagecreatefromgif($originalFile);

					break;
				case 'png':
					$sourceImage = imagecreatefrompng($originalFile);

					break;
				default:
					$sourceImage = null;

					break;
			}

			// Check for image resource type
			if (is_resource($sourceImage) && get_resource_type($sourceImage) === 'gd') {
				// Get original width and height
				$originalWidth = imagesx($sourceImage);
				$originalHeight = imagesy($sourceImage);

				$offsetX = 0;
				$offsetY = 0;

				$cropX = 0;
				$cropY = 0;

				foreach ($settingsParams['thumbs'] as $thumbName => $thumbParam) {
					if (is_array($thumbParam)) {
						if (isset($thumbParam['width']) && is_array($thumbParam['width']) && count($thumbParam['width']) === 1) {
							list($newWidth, $newHeight) = $this->byWidth($originalWidth, $originalHeight, $thumbParam['width'][0]);
						} elseif (isset($thumbParam['height']) && is_array($thumbParam['height']) && count($thumbParam['height']) === 1) {
							list($newWidth, $newHeight) = $this->byHeight($originalWidth, $originalHeight, $thumbParam['height'][0]);
						} elseif (isset($thumbParam['shorter']) && is_array($thumbParam['shorter']) && count($thumbParam['shorter']) === 2) {
							list($newWidth, $newHeight) = $this->byShorter($originalWidth, $originalHeight, $thumbParam['shorter'][0], $thumbParam['shorter'][1]);
						} elseif (isset($thumbParam['longer']) && is_array($thumbParam['longer']) && count($thumbParam['longer']) === 2) {
							list($newWidth, $newHeight) = $this->byLonger($originalWidth, $originalHeight, $thumbParam['longer'][0], $thumbParam['longer'][1]);
						} elseif (isset($thumbParam['fit']) && is_array($thumbParam['fit']) && count($thumbParam['fit']) === 2) {
							list($newWidth, $newHeight, $offsetX, $offsetY, $cropX, $cropY) = $this->byFit($originalWidth, $originalHeight, $thumbParam['fit'][0], $thumbParam['fit'][1]);
						} elseif (isset($thumbParam['fit']) && is_array($thumbParam['fit']) && count($thumbParam['fit']) === 3) {
							list($newWidth, $newHeight, $offsetX, $offsetY, $cropX, $cropY) = $this->byFit($originalWidth, $originalHeight, $thumbParam['fit'][0], $thumbParam['fit'][1], $thumbParam['fit'][2]);
						} elseif (isset($thumbParam['square']) && is_array($thumbParam['square']) && count($thumbParam['square']) === 1) {
							list($newWidth, $newHeight, $offsetX, $offsetY, $cropX, $cropY) = $this->bySquare($originalWidth, $originalHeight, $thumbParam['square'][0]);
						} elseif (isset($thumbParam['square']) && is_array($thumbParam['square']) && count($thumbParam['square']) === 2) {
							list($newWidth, $newHeight, $offsetX, $offsetY, $cropX, $cropY) = $this->bySquare($originalWidth, $originalHeight, $thumbParam['square'][0], $thumbParam['square'][1]);
						} else {
							$newWidth = $originalWidth;
							$newHeight = $originalHeight;
						}

						$newImage = imagecreatetruecolor($newWidth, $newHeight);

						if (is_array($settingsParams['background'])) {
							// Set background color and transparent indicates
							imagefill($newImage, 0, 0, imagecolorallocatealpha($newImage, $settingsParams['background'][0], $settingsParams['background'][1], $settingsParams['background'][2], $settingsParams['background'][3]));
						}

						imagecopyresampled($newImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);

						if (isset($thumbParam['square']) && is_array($thumbParam['square']) || isset($thumbParam['fit']) && is_array($thumbParam['fit'])) {
							$fitImage = imagecreatetruecolor($newWidth + (2 * $offsetX) - (2 * $cropX), $newHeight + (2 * $offsetY) - (2 * $cropY));

							if (is_array($settingsParams['background'])) {
								// Set background color and transparent indicates
								imagefill($fitImage, 0, 0, imagecolorallocatealpha($fitImage, $settingsParams['background'][0], $settingsParams['background'][1], $settingsParams['background'][2], $settingsParams['background'][3]));
							}

							imagecopyresampled($fitImage, $newImage, $offsetX, $offsetY, $cropX, $cropY, $newWidth, $newHeight, $newWidth, $newHeight);

							$newImage = $fitImage;
						}

						imagealphablending($newImage, false);
						imagesavealpha($newImage, true);

						if (isset($thumbParam['watermark']) && file_exists($settingsParams['watermark'])) {
							$watermarkImage = imagecreatefrompng($settingsParams['watermark']);

							$watermarkPositions = $this->getPosition(imagesx($newImage), imagesy($newImage), imagesx($watermarkImage), imagesy($watermarkImage), $offsetX, $offsetY, $thumbParam['watermark']);

							// Set transparent
							imagealphablending($newImage, true);
							imagecopy($newImage, $watermarkImage, $watermarkPositions[0], $watermarkPositions[1], 0, 0, imagesx($watermarkImage), imagesy($watermarkImage));
						}

						$thumbFile = str_replace('original', $thumbName, $originalFile);

						// Get image resource
						switch ($fileExtension) {
							case 'gif':
								imagegif($newImage, $thumbFile);

								break;
							case 'png':
								imagepng($newImage, $thumbFile);

								break;
							default:
								imagejpeg($newImage, $thumbFile, 100);

								break;
						}
					}
				}
			}
		}
	}

	/**
	 * Get extension from original name
	 *
	 * @param string $originalName Name of original file
	 * @return string Extension of uploaded file
	 */
	public function getExtension($originalName) {
		$fileName = strtolower($originalName);
		$fileParts = explode('.', $fileName);
		$fileExtension = end($fileParts);

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
	 * Get file size in bytes
	 *
	 * @param integer $sizeValue File size
	 * @return integer Size of uploaded file
	 */
	public function getBytes($sizeValue) {
		$sizeValue = trim($sizeValue);

		if (is_numeric($sizeValue)) {
			$sizeLetter = 'm';
		} else {
			$sizeLetter = strtolower($sizeValue[strlen($sizeValue)-1]);
		}

		switch ($sizeLetter) {
			case 'g':
				$sizeValue *= 1073741824;

				break;
			case 'm':
				$sizeValue *= 1048576;

				break;
			case 'k':
				$sizeValue *= 1024;

				break;
		}

		return intval($sizeValue);
	}

	/**
	 * Get position of watermark image
	 *
	 * @param integer $newWidth New width of uploaded image
	 * @param integer $newHeight New height of uploaded image
	 * @param integer $watermarkWidth Original width of watermark image
	 * @param integer $watermarkHeight Original height of watermark image
	 * @param integer $offsetX Horizontal offset
	 * @param integer $offsetY Vertical offset
	 * @param integer $positionValue Value for position watermark, value between 1 and 9
	 * @return array Coordinates of position watermark
	 */
	public function getPosition($newWidth, $newHeight, $watermarkWidth, $watermarkHeight, $offsetX = 0, $offsetY = 0, $positionValue = 1) {
		switch (intval($positionValue)) {
			case 1: // Top left
				return array(0 + $offsetX, 0 + $offsetY);

				break;
			case 2: // Top center
				return array(($newWidth / 2) - ($watermarkWidth / 2), 0 + $offsetY);

				break;
			case 3: // Top right
				return array(($newWidth - $watermarkWidth) - $offsetX, 0 + $offsetY);

				break;
			case 4: // Middle left
				return array(0 + $offsetX, ($newHeight / 2) - ($watermarkHeight /2));

				break;
			case 5: // Middle center
				return array(($newWidth / 2) - ($watermarkWidth / 2), ($newHeight / 2) - ($watermarkHeight /2));

				break;
			case 6: // Middle right
				return array(($newWidth - $watermarkWidth) - $offsetX, ($newHeight / 2) - ($watermarkHeight /2));

				break;
			case 7: // Bottom left
				return array(0 + $offsetX, ($newHeight - $watermarkHeight) - $offsetY);

				break;
			case 8: // Bottom center
				return array(($newWidth / 2) - ($watermarkWidth / 2), ($newHeight - $watermarkHeight) - $offsetY);

				break;
			case 9: // Bottom right
				return array(($newWidth - $watermarkWidth) - $offsetX, ($newHeight - $watermarkHeight) - $offsetY);

				break;
			default:
				return array(0 - $offsetX, 0 - $offsetY);

				break;
		}
	}

	/**
	 * Create image dimension by new width.
	 *
	 * @param integer $originalWidth Original width of uploaded image
	 * @param integer $originalHeight Original height of uploaded image
	 * @param integer $newWidth Set new image width
	 * @return array New width and height
	 */
	public function byWidth($originalWidth, $originalHeight, $newWidth) {
		$newWidth = intval($newWidth);

		if ($newWidth > $originalWidth) {
			$newWidth = $originalWidth;
			$newHeight = $originalHeight;
		} else {
			$newHeight = intval($newWidth * ($originalHeight / $originalWidth));
		}

		return array($newWidth, $newHeight);
	}

	/**
	 * Create image dimension by new height.
	 *
	 * @param integer $originalWidth Original width of uploaded image
	 * @param integer $originalHeight Original height of uploaded image
	 * @param integer $newHeight Set new image height
	 * @return array New width and height
	 */
	public function byHeight($originalWidth, $originalHeight, $newHeight) {
		$newHeight = intval($newHeight);

		if ($newHeight > $originalHeight) {
			$newHeight = $originalHeight;
			$newWidth = $originalWidth;
		} else {
			$newWidth = intval($newHeight * ($originalWidth / $originalHeight));
		}

		return array($newWidth, $newHeight);
	}

	/**
	 * Create image dimension by shorter side.
	 *
	 * @param integer $originalWidth Original width of uploaded image
	 * @param integer $originalHeight Original height of uploaded image
	 * @param integer $newWidth Set new image min width
	 * @param integer $newHeight Set new image min height
	 * @return array New width and height
	 */
	public function byShorter($originalWidth, $originalHeight, $newWidth, $newHeight) {
		$newWidth = intval($newWidth);
		$newHeight = intval($newHeight);

		if ($originalWidth < $originalHeight) {
			list($newWidth, $newHeight) = $this->byWidth($originalWidth, $originalHeight, $newWidth);
		} else {
			list($newWidth, $newHeight) = $this->byHeight($originalWidth, $originalHeight, $newHeight);
		}

		return array($newWidth, $newHeight);
	}

	/**
	 * Create image dimension by longer side.
	 *
	 * @param integer $originalWidth Original width of uploaded image
	 * @param integer $originalHeight Original height of uploaded image
	 * @param integer $newWidth Set new image max width
	 * @param integer $newHeight Set new image max height
	 * @return array New width and height
	 */
	public function byLonger($originalWidth, $originalHeight, $newWidth, $newHeight) {
		$newWidth = intval($newWidth);
		$newHeight = intval($newHeight);

		if ($originalWidth > $originalHeight) {
			list($newWidth, $newHeight) = $this->byWidth($originalWidth, $originalHeight, $newWidth);
		} else {
			list($newWidth, $newHeight) = $this->byHeight($originalWidth, $originalHeight, $newHeight);
		}

		return array($newWidth, $newHeight);
	}

	/**
	 * Create image dimension by fit.
	 *
	 * @param integer $originalWidth Original width of uploaded image
	 * @param integer $originalHeight Original height of uploaded image
	 * @param integer $newWidth Set new image width
	 * @param integer $newHeight Set new image height
	 * @param boolean $originalKeep Save original shape
	 * @return array New width and height and offsets of position with keeping original shape
	 */
	public function byFit($originalWidth, $originalHeight, $newWidth, $newHeight, $originalKeep = false) {
		$newWidth = intval($newWidth);
		$newHeight = intval($newHeight);

		$offsetX = 0;
		$offsetY = 0;
		$cropX = 0;
		$cropY = 0;

		if ($originalKeep === true) {
			if ($originalWidth > $originalHeight) {
				if ($newWidth < $newHeight) {
					$newSizes = $this->byLonger($originalWidth, $originalHeight, $newWidth, $newHeight);
				} else {
					$newSizes = $this->byShorter($originalWidth, $originalHeight, $newWidth, $newHeight);
				}
			} else {
				if ($newWidth > $newHeight) {
					$newSizes = $this->byLonger($originalWidth, $originalHeight, $newWidth, $newHeight);
				} else {
					$newSizes = $this->byShorter($originalWidth, $originalHeight, $newWidth, $newHeight);
				}
			}
		} else {
			if ($originalWidth > $originalHeight) {
				$newSizes = $this->byLonger($originalWidth, $originalHeight, $newWidth, $newHeight);
			} else {
				$newSizes = $this->byShorter($originalWidth, $originalHeight, $newWidth, $newHeight);
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

		return array($newSizes[0], $newSizes[1], $offsetX, $offsetY, $cropX, $cropY);
	}

	/**
	 * Create image dimension to square.
	 *
	 * @param integer $originalWidth Original width of uploaded image
	 * @param integer $originalHeight Original height of uploaded image
	 * @param integer $newSide Set new image side
	 * @param boolean $originalKeep Save original shape
	 * @return array New width and height with coordinates of crop or offsets of position
	 */
	public function bySquare($originalWidth, $originalHeight, $newSide, $originalKeep = false) {
		$newSide = intval($newSide);

		$offsetX = 0;
		$offsetY = 0;
		$cropX = 0;
		$cropY = 0;

		if ($originalKeep === true) {
			list($newWidth, $newHeight) = $this->byLonger($originalWidth, $originalHeight, $newSide, $newSide);

			if ($newSide > $newWidth) {
				$offsetX = ($newSide - $newWidth) / 2;
			}

			if ($newSide > $newHeight) {
				$offsetY = ($newSide - $newHeight) / 2;
			}
		} else {
			list($newWidth, $newHeight) = $this->byShorter($originalWidth, $originalHeight, $newSide, $newSide);

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

		return array($newWidth, $newHeight, $offsetX, $offsetY, $cropX, $cropY);
	}

	/**
	 * Validation file when is required.
	 *
	 * @param Model $model Reference to model
	 * @param array $valudateValue Array of settings validation
	 * @return boolean Success of validation
	 */
	public function fileRequired(Model $model, $validateValue) {
		$validateKeys = array_keys($validateValue);
		$validateVariable = array_shift($validateValue);

		if ($model->id) {
			$file = $model->findById($model->id);

			if (!empty($file[$model->alias][$validateKeys[0]]) && file_exists($this->settings[$model->alias][$validateKeys[0]]['path'] . DS . $file[$model->alias][$validateKeys[0]])) {
				return true;
			}
		}

		if (!empty($validateVariable['tmp_name'])) {
			return true;
		}

		return false;
	}

	/**
	 * Validation file type.
	 *
	 * @todo Rewrite to use Validation::mimeType();
	 * @param Model $model Reference to model
	 * @param array $valudateValue Array of settings validation
	 * @return boolean Success of validation
	 */
	public function fileType(Model $model, $validateValue) {
		$validateKeys = array_keys($validateValue);
		$validateVariable = array_shift($validateValue);

		if (empty($validateVariable['tmp_name'])) {
			return true;
		}

		if (in_array($validateVariable['type'], $this->settings[$model->alias][$validateKeys[0]]['types'])) {
			return true;
		}

		return false;
	}

	/**
	 * Validation file extension.
	 *
	 * @todo Rewrite to use Validation::extension();
	 * @param Model $model Reference to model
	 * @param array $valudateValue Array of settings validation
	 * @return boolean Success of validation
	 */
	public function fileExtension(Model $model, $validateValue) {
		$validateKeys = array_keys($validateValue);
		$validateVariable = array_shift($validateValue);

		if (empty($validateVariable['tmp_name'])) {
			return true;
		}

		if (in_array($this->getExtension($validateVariable['name']), $this->settings[$model->alias][$validateKeys[0]]['extensions'])) {
			return true;
		}

		return false;
	}
}