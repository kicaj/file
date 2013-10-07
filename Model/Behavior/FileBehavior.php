<?php
/**
 * File Behavior for upload files and processing images.
 *
 * Tested on CakePHP 2.3.9/PHP 5.4.0
 *
 * @todo          Rewrite for use built-in validation (e.g. Validation::extension(); and Validation::mimeType();) and other addition (e.g. CakeNumber::fromReadableSize();) - Q4 2013
 * @todo          Rewrite to PHP 5.5 for new function from GD2 library (e.g. imagescale(); or imagecrop();) - Q1 2014
 * @copyright     Radosław Zając, kicaj (kicaj@kdev.pl)
 * @link          http://repo.kdev.pl/filebehavior
 * @package       Cake.Model.Behavior
 * @version       1.2.20131007
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
class FileBehavior extends ModelBehavior {

	/**
	 * Default settings for uploaded files
	 * 
	 * @var Default settings
	 */
	public $default = array(
		'types' => array( // default allowed types
			'image/jpeg',
			'image/jpg',
			'image/pjpeg',
			'image/pjpg',
			'image/png',
			'image/x-png',
			'image/gif'),
		'extensions' => array( // default allowed extensions
			'jpeg',
			'jpg',
			'pjpg',
			'pjpeg',
			'png',
			'gif'),
		'thumbs' => array(),
		'path' => 'files');
	/**
	 * Default validations for uploaded files
	 * 
	 * @var array Default validations
	 */
	public $validate = array(
		'max' => array(
			'rule' => array('fileSize', '<=', '10M'),
			'message' => 'Niestety, ale maksymalny rozmiar pliku został przekroczony!'),
		'type' => array(
			'rule' => 'fileType',
			'message' => 'Niestety, ale niedozwolony typ pliku!'),
		'ext' => array(
			'rule' => 'fileExtension',
			'message' => 'Niestety, ale niedozwolony typ rozszerzenia pliku!'));
	/**
	 * Array of files to upload.
	 * 
	 * @var array Files to upload
	 */
	public $files = array();
	
	/**
	 * Setup behavior
	 * 
	 * @param Model $model Reference to model
	 * @param array $settings Array of settings
	 */
	public function setup(Model $model, $settings = array()) {
		foreach($settings as $field => $array) {
			// Set validations rules
			$validation = array();
			
			if(isset($model->validate[$field])) {
				$validation = $model->validate[$field];
			}
			
			$model->validate[$field] = array_merge($this->validate, $validation);
			
			$this->settings[$model->name][$field] = array_merge($this->default, $array);
		}
	}
	
	/**
	 * Callback beforeSave
	 * 
	 * @param Model $model Reference to model
	 */
	public function beforeSave(Model $model) {
		foreach($this->settings[$model->name] as $fieldName => $fieldOptions) {
			// Check for temporary file
			if(isset($model->data[$model->name][$fieldName]) && !empty($model->data[$model->name][$fieldName]['name']) && file_exists($model->data[$model->name][$fieldName]['tmp_name'])) {
				// Settings for files
				$this->files[$fieldName] = $model->data[$model->name][$fieldName];
				$this->files[$fieldName]['path'] = $this->prepareDir($fieldOptions['path']);
				$this->files[$fieldName]['name'] = $this->prepareName($model, $fieldName);
				
				$model->data[$model->name][$fieldName] = $this->files[$fieldName]['name'];
			} else {
				// Delete file array from data when is not attached
				unset($model->data[$model->alias][$fieldName]);
			}
		}
		
		return true;
	}
	
	/**
	 * Callback afterSave
	 * 
	 * @param Model $model Reference to model
	 * @param boolean $isCreated True if this save created a new record
	 */
	public function afterSave(Model $model, $isCreated) {
		if($isCreated !== true && empty($this->files)) {
		
		} else {
			$this->prepareFile($model);
		}
	}
	
	// Callback before delete
	/**
	 * Callback beforeDelete
	 * 
	 * @param Model $model Reference to model
	 * @param boolean $cascade If true records that depend on this record will also be deleted
	 * @return boolean True if is success
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
		if(isset($model->id)) {
			$dataField = $model->findById($model->id);
			
			if(is_array($dataField) && !empty($dataField) && is_file($this->files[$fieldName]['path'] . DS . $dataField[$model->name][$fieldName])) {
				$filePattern = $this->settings[$model->name][$fieldName]['path'] . DS . substr($dataField[$model->name][$fieldName], 0, 14);
				
				foreach(glob($filePattern .'*') as $fileName) {
					// Remove file
					@unlink($fileName);
				}
			}
		}
		
		$nameFile = substr(String::uuid(), -27, 14) .'_original.'. $this->getExtension($this->files[$fieldName]['name']);
		
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
		
		if(!is_dir($dirPath) && strlen($dirPath) > 0) {
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
		foreach($this->files as $fieldName => $fieldOptions) {
			// Name of original file
			$fileName = $fieldOptions['path'] . DS . $this->files[$fieldName]['name'];
			
			if(move_uploaded_file($this->files[$fieldName]['tmp_name'], $fileName)) {
				if(isset($this->settings[$model->name][$fieldName]['thumbs'])) {
					$this->prepareThumbs($fileName, $this->settings[$model->name][$fieldName]['thumbs']);
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
				
		foreach($this->settings[$model->name] as $fieldName => $fieldOptions) {
			// Check is field in model schema
			if(isset($modelSchema[$fieldName])) {
				$dataField = $model->findById($model->id);
				
				if(is_array($dataField) && !empty($dataField[$model->name][$fieldName])) {
					// Pattern for original file with thumbs
					$filePattern = $this->settings[$model->name][$fieldName]['path'] . DS . substr($dataField[$model->name][$fieldName], 0, 14);
					
					foreach(glob($filePattern .'*') as $fileName) {
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
	public function prepareThumbs($originalFile, $thumbParams) {
		if(is_file($originalFile) && is_array($thumbParams)) {
			// Get extension from original file
			$fileExtension = $this->getExtension($originalFile);
			
			// Get image resource
			switch($fileExtension) {
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
			if(is_resource($sourceImage) && get_resource_type($sourceImage) === 'gd') {
				// Get original width and height
				$originalWidth = imagesx($sourceImage);
				$originalHeight = imagesy($sourceImage);
				
				$cropX = 0;
				$cropY = 0;
				
				foreach($thumbParams as $thumbName => $thumbParam) {
					if(is_array($thumbParam)) {
						if(isset($thumbParam['width']) && is_array($thumbParam['width']) && count($thumbParam['width']) === 1) {
							list($newWidth, $newHeight) = $this->byWidth($originalWidth, $originalHeight, $thumbParam['width'][0]);
						} elseif(isset($thumbParam['height']) && is_array($thumbParam['height']) && count($thumbParam['height']) === 1) {
							list($newWidth, $newHeight) = $this->byHeight($originalWidth, $originalHeight, $thumbParam['height'][0]);
						} elseif(isset($thumbParam['shorter']) && is_array($thumbParam['shorter']) && count($thumbParam['shorter']) === 2) {
							list($newWidth, $newHeight) = $this->byShorter($originalWidth, $originalHeight, $thumbParam['shorter'][0], $thumbParam['shorter'][1]);
						} elseif(isset($thumbParam['longer']) && is_array($thumbParam['longer']) && count($thumbParam['longer']) === 2) {
							list($newWidth, $newHeight) = $this->byLonger($originalWidth, $originalHeight, $thumbParam['longer'][0], $thumbParam['longer'][1]);
						} elseif(isset($thumbParam['fit']) && is_array($thumbParam['fit']) && count($thumbParam['fit']) === 2) {
							list($newWidth, $newHeight) = $this->byFit($originalWidth, $originalHeight, $thumbParam['fit'][0], $thumbParam['fit'][1]);
						} elseif(isset($thumbParam['square']) && is_array($thumbParam['square']) && count($thumbParam['square']) === 1) {
							list($newWidth, $newHeight, $cropX, $cropY) = $this->bySquare($originalWidth, $originalHeight, $thumbParam['square'][0]);
						} else {
							$newWidth = $originalWidth;
							$newHeight = $originalHeight;
						}
						
						$newImage = @imagecreatetruecolor($newWidth, $newHeight);
						@imagealphablending($newImage, false);
						@imagesavealpha($newImage, true);
						@imagefill($newImage, 0, 0, imagecolorallocate($newImage, 255, 255, 255, 127));
						imagecopyresampled($newImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);
						
						if(isset($thumbParam['square'])) {
							$newWidth = $newHeight = min($newWidth, $newHeight);
							
							$cropImage = imagecreatetruecolor($newWidth, $newHeight);
							imagecopyresampled($cropImage, $newImage, 0, 0, $cropX, $cropY, $newWidth, $newHeight, $newWidth, $newHeight);
							
							$newImage = $cropImage;
						}
						
						$thumbFile = str_replace('original', $thumbName, $originalFile);
						
						// Get image resource
						switch($fileExtension) {
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
		
		switch($fileExtension) {
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
		
		if(is_numeric($sizeValue)) {
			$sizeLetter = 'm';
		} else {
			$sizeLetter = strtolower($sizeValue[strlen($sizeValue)-1]);
		}
		
		switch($sizeLetter) {
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
	 * Create image dimension by new width.
	 * 
	 * @param integer $originalWidth Original width of uploaded image
	 * @param integer $originalHeight Original height of uploaded image
	 * @param integer $newWidth Set new image width
	 * @return array New width and height
	 */
	public function byWidth($originalWidth, $originalHeight, $newWidth) {
		$newWidth = intval($newWidth);
		
		if($newWidth > $originalWidth) {
			$newWidth = $originalWidth;
			$newHeight = $originalHeight;
		} else {
			$newHeight = $newWidth * ($originalHeight / $originalWidth);
		}
		
		return array(intval($newWidth), intval($newHeight));
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
		
		if($newHeight > $originalHeight) {
			$newHeight = $originalHeight;
			$newWidth = $originalWidth;
		} else {
			$newWidth = $newHeight * ($originalWidth / $originalHeight);
		}
		
		return array(intval($newWidth), intval($newHeight));
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
		
		if($originalWidth < $originalHeight) {
			list($newWidth, $newHeight) = $this->byWidth($originalWidth, $originalHeight, $newWidth);
		} else {
			list($newWidth, $newHeight) = $this->byHeight($originalWidth, $originalHeight, $newHeight);
		}
		
		return array(intval($newWidth), intval($newHeight));
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
		
		if($originalWidth > $originalHeight) {
			list($newWidth, $newHeight) = $this->byWidth($originalWidth, $originalHeight, $newWidth);
		} else {
			list($newWidth, $newHeight) = $this->byHeight($originalWidth, $originalHeight, $newHeight);
		}
		
		return array(intval($newWidth), intval($newHeight));
	}
	
	/**
	 * Create image dimension by fit.
	 * 
	 * @param integer $originalWidth Original width of uploaded image
	 * @param integer $originalHeight Original height of uploaded image
	 * @param integer $newWidth Set new image width
	 * @param integer $newHeight Set new image height
	 * @return array New width and height
	 */
	public function byFit($originalWidth, $originalHeight, $newWidth, $newHeight) {
		$newWidth = intval($newWidth);
		$newHeight = intval($newHeight);
		
		list($newWidth, $newHeight) = $this->byLonger($originalWidth, $originalHeight, $newWidth, $newHeight);
		
		return array(intval($newWidth), intval($newHeight));
	}
	
	/**
	 * Create image dimension to square.
	 * 
	 * @param integer $originalWidth Original width of uploaded image
	 * @param integer $originalHeight Original height of uploaded image
	 * @param integer $newSide Set new image side
	 * @return array New width and height with coordinates of crop
	 */
	public function bySquare($originalWidth, $originalHeight, $newSide) {
		$newSide = intval($newSide);
		
		list($newWidth, $newHeight) = $this->byShorter($originalWidth, $originalHeight, $newSide, $newSide);
		
		$cropWidth = 0;
		$cropHeight = 0;
		
		if($newWidth > $newHeight) {
			$cropWidth = ($newWidth - $newHeight)/2;
		} else {
			$cropHeight = ($newHeight - $newWidth)/2;
		}
		
		return array($newWidth, $newHeight, $cropWidth, $cropHeight);
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
		
		if($model->id) {
			$file = $model->findById($model->id);
			
			if(!empty($file[$model->name][$validateKeys[0]]) && file_exists($this->settings[$model->name][$validateKeys[0]]['path'] . DS . $file[$model->name][$validateKeys[0]])) {
				return true;
			}
		}
		
		if(!empty($validateVariable['tmp_name'])) {
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
		
		if(empty($validateVariable['tmp_name'])) {
			return true;
		}
		
		if(in_array($validateVariable['type'], $this->settings[$model->name][$validateKeys[0]]['types'])) {
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
		
		if(empty($validateVariable['tmp_name'])) {
			return true;
		}
		
		if(in_array($this->getExtension($validateVariable['name']), $this->settings[$model->name][$validateKeys[0]]['extensions'])) {
			return true;
		}
		
		return false;
	}
}