<?php namespace ProcessWire;

class AutoAvif extends WireData implements Module, ConfigurableModule {

	protected $avifCreated;

	/**
	 * Construct
	 */
	public function __construct() {
		parent::__construct();
		$this->quality = 70;
		$this->speed = 6;
		$this->createForExisting = 0;
	}

	/**
	 * Ready
	 */
	public function ready() {
		if($this->wire()->config->enableAvif !== false) {
			$this->addHookBefore('Pageimage::size', $this, 'beforePageimageSize', ['priority' => 200]);
			$this->addHookBefore('Pageimages::delete', $this, 'beforePageimagesDelete');
			$this->addHookBefore('ProcessPageEditImageSelect::executeVariations', $this, 'beforeExecuteVariations');
			if($this->createForExisting) {
				$this->addHookAfter('Pageimage::size', $this, 'afterPageimageSize');
			}
		}
	}

	/**
	 * Before Pageimage::size
	 * Add resize hooks
	 *
	 * @param HookEvent $event
	 */
	protected function beforePageimageSize(HookEvent $event) {
		/** @var Pageimage $pageimage */
		$pageimage = $event->object;
		$width = $event->arguments(0);
		$height = $event->arguments(1);
		$options = $event->arguments(2);
		if(!is_array($options)) $options = $pageimage->sizeOptionsToArray($options);
		$path = $pageimage->pagefiles->path();

		// Skip admin thumbnail
		if($this->wire()->config->admin && ($width === 260 || $height === 260)) return;

		// Return if AVIF not allowed for this pageimage
		if(!$this->allowAvif($pageimage, $width, $height, $options)) return;

		$this->avifCreated = false;

		// GD
		$this->wire()->addHookAfter('ImageSizerEngineGD::imSaveReady', function(HookEvent $event) use ($path) {
			/** @var \GdImage $gd_image */
			$gd_image = $event->arguments(0);
			$filename = $event->arguments(1);
			$this->avifCreated = true;
			if(!function_exists('imageavif')) return;
			set_time_limit(60);
			$path_parts = pathinfo($filename);
			$avif_filename = $path . $path_parts['filename'] . '.avif';
			if(is_file($avif_filename)) $this->wire()->files->unlink($avif_filename);

			imageavif($gd_image, $avif_filename, $this->quality, $this->speed);
		});

		// Imagick
		$this->wire()->addHookAfter('ImageSizerEngineIMagick::imSaveReady', function(HookEvent $event) use ($path) {
			/** @var \Imagick $im_image */
			$im_image = $event->arguments(0);
			$filename = $event->arguments(1);
			$this->avifCreated = true;
			set_time_limit(60);
			$path_parts = pathinfo($filename);
			$avif_filename = $path . $path_parts['filename'] . '.avif';
			if(is_file($avif_filename)) $this->wire()->files->unlink($avif_filename);

			$im_image->setImageFormat('avif');
			$im_image->setCompressionQuality($this->quality);
			$im_image->setOption('heic:speed', $this->speed);
			$im_image->writeImage($avif_filename);
		});
	}

	/**
	 * After Pageimage::size
	 * Create AVIF if missing
	 *
	 * @param HookEvent $event
	 */
	protected function afterPageimageSize(HookEvent $event) {
		/** @var Pageimage $pageimage */
		$pageimage = $event->return;
		$width = $event->arguments(0);
		$height = $event->arguments(1);
		$options = $event->arguments(2) ?: [];

		// Skip admin thumbnail
		if($this->wire()->config->admin && ($width === 260 || $height === 260)) return;

		// Return early if an attempt was made to create an AVIF file, successful or not
		if($this->avifCreated) return;

		// If there is no AVIF file then call size() again with forceNew true
		$avif_filename = $this->getAvifFilename($pageimage);
		if(!is_file($avif_filename)) {
			$original = $pageimage->getOriginal();
			$options['forceNew'] = true;
			// Set noDelay option in case DelayedImageVariations module is installed
			$options['noDelay'] = true;
			$original->size($width, $height, $options);
		}
	}

	/**
	 * Get the AVIF filename for the supplied Pageimage
	 *
	 * @param Pageimage $pageimage
	 */
	public function getAvifFilename(Pageimage $pageimage) {
		return str_replace(".$pageimage->ext", '.avif', $pageimage->filename);
	}

	/**
	 * Before Pageimages::delete
	 * Delete any AVIF files that correspond to variations of the deleted image
	 *
	 * @param HookEvent $event
	 */
	protected function beforePageimagesDelete(HookEvent $event) {
		/** @var Pageimage $pageimage */
		$pageimage = $event->arguments(0);
		foreach($pageimage->getVariations() as $variation) { /** @var Pageimage $variation */
			$avif_filename = $this->getAvifFilename($variation);
			if(is_file($avif_filename)) $this->wire()->files->unlink($avif_filename);
		}
	}

	/**
	 * ProcessPageEditImageSelect::executeVariations
	 * Delete any AVIF files that correspond to variations deleted via ProcessPageEditImageSelect
	 *
	 * @param HookEvent $event
	 */
	protected function beforeExecuteVariations(HookEvent $event) {
		/** @var ProcessPageEditImageSelect $ppeis */
		$ppeis = $event->object;
		$delete = $this->wire()->input->post('delete');
		if(is_array($delete) && count($delete)) {
			$pageimage = $ppeis->getPageimage();
			$variations = $pageimage->getVariations();
			foreach($delete as $basename) {
				$variation = $variations->get($basename);
				if(!$variation) continue;
				$avif_filename = $this->getAvifFilename($variation);
				if(is_file($avif_filename)) $this->wire()->files->unlink($avif_filename);
			}
		}
	}

	/**
	 * Allow an AVIF file to be created for this Pageimage variation?
	 *
	 * @param Pageimage $pageimage
	 * @param int $width
	 * @param int $height
	 * @param array|string|int $options
	 * @return bool
	 */
	public function ___allowAvif(Pageimage $pageimage, $width, $height, $options) {
		// Can check things like $pageimage->field, $pageimage->page and $pageimage->ext
		return true;
	}

	/**
	 * Config inputfields
	 *
	 * @param InputfieldWrapper $inputfields
	 */
	public function getModuleConfigInputfields($inputfields) {
		$modules = $this->wire()->modules;

		// Check that the environment supports AVIF format and show warning if not
		if($modules->isInstalled('ImageSizerEngineIMagick')) {
			if(extension_loaded('imagick')) {
				$supported_formats = \Imagick::queryFormats();
				$supported_formats = array_map('strtolower', $supported_formats);
				if(!in_array('avif', $supported_formats)) {
					$this->wire()->warning($this->_('The installed version of the imagick extension does not support AVIF format.'));
				}
			} else {
				$this->wire()->warning($this->_('ImageSizerEngineIMagick is installed but the needed imagick PHP extension is not loaded.'));
			}
		} else {
			if(!function_exists('imageavif')) {
				$this->wire()->warning($this->_('The installed version of GD does not support AVIF format.'));
			} else {
				set_error_handler(function($errorNumber, $errorString) {
					$errorString = trim($errorString);
					$this->wire()->warning($errorString, 'noGroup');
				}, E_WARNING);
				$test = imagecreatefromavif($this->wire()->config->paths->$this . 'test.avif');
				if(!$test) $this->wire()->warning($this->_('Your environment does not support AVIF format.'));
				restore_error_handler();
			}
		}

		/** @var InputfieldFieldset $fs */
		$fs = $modules->get('InputfieldFieldset');
		$fs->label = $this->_('AVIF generation settings');
		$fs->description = $this->_('These settings are passed to the GD/ImageMagick AVIF generation methods.');
		$inputfields->add($fs);

		/** @var InputfieldInteger $f */
		$f = $modules->get('InputfieldInteger');
		$f_name = 'quality';
		$f->name = $f_name;
		$f->label = $this->_('Quality (1 – 100)');
		$f->inputType = 'number';
		$f->min = 1;
		$f->max = 100;
		$f->value = $this->$f_name;
		$f->columnWidth = 50;
		$fs->add($f);

		/** @var InputfieldInteger $f */
		$f = $modules->get('InputfieldInteger');
		$f_name = 'speed';
		$f->name = $f_name;
		$f->label = $this->_('Speed (0 – 9)');
		$f->inputType = 'number';
		$f->min = 0;
		$f->max = 9;
		$f->value = $this->$f_name;
		$f->columnWidth = 50;
		$fs->add($f);

		/** @var InputfieldCheckbox $f */
		$f = $modules->get('InputfieldCheckbox');
		$f_name = 'createForExisting';
		$f->name = $f_name;
		$f->label = $this->_('Create AVIF files for existing variations');
		$f->description = $this->_('When this option is checked AVIF files will be created for any existing image variations at the time they are next requested.');
		$f->checked = $this->$f_name === 1 ? 'checked' : '';
		$inputfields->add($f);
	}

}
