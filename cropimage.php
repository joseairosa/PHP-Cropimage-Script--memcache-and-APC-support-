<?php
/**
 * Created and developed by José P. Airosa (http://www.joseairosa.com/)
 *
 * This file will enable you to automaticaly resize images without loosing quality.
 * It uses a simple math formulas to calculate the desired final size and then rerenders the image.
 *
 * The usage is very simples. On you HTML code just create an image and replace the src with cropimage.php?src=img/myimage.jpg&size=320x240
 *
 * src - the source of the image itself
 * size - the size that the image should have. If the size is not directly compatible with the original size it will display a simple background and the image with
 * the correct size on top.
 *
 * Example: <img src="cropimage.php?src=img/myimage.jpg&size=320x240" alt="" />
 *
 * If it's a square image you can just use myimage.jpg&size=320 and it will make a 320x320 image.
 *
 */
 
ini_set("memory_limit", "64M");
ini_set('gd.jpeg_ignore_warning', 1);

if(isset($_GET['rewrite'])) {
	$_GET['src'] = implode('_',array_slice(explode('_',$_GET['src']), 2));
	$_GET['src'] = 'img/'.$_GET['id'].'/'.$_GET['src'];
}
if(isset($_GET['size']) && $_GET['size'] == 'original') {
	unset($_GET['size']);
}

// APC
define("USE_APC", false);
// Memcache
define("USE_MEMCACHE", true);
// Choose or tools of trade :) Remember, you should only have 1 selected at the same time
define("USE_CACHE", (isset($_GET['no_cache']) && $_GET['no_cache'] == 1 ? false : ((USE_MEMCACHE || USE_APC) ? false : true ) ));
// Should we use server ram (faster) to store our temporary image or file support (slower)
define("MEMORY_METHOD", true);
// Should we allow the browser to load its cached version if present?
define("CACHE302", (isset($_GET['no_cache']) && $_GET['no_cache'] == 1 ? false : true ));
define("MEMCACHE_PATH", 'tcp://localhost:11211?persistent=1&weight;=1&timeout;=1&retry;_interval=15');
// If you get any errors, just set this as define("STREAM_HANDLER", '');
define("STREAM_HANDLER", 'ob_gzhandler');

// Set image quality
define("IMAGE_QUALITY", 90);
define("PNG_IMAGE_QUALITY", round(abs((IMAGE_QUALITY - 100) / 11.111111)));

// Use watermark
define("USE_WATERMARK", false);
// Path to the watermark image with reference to this file
define("WATERMARK",'');
// How many pixels should we move the watermark from the far most right of the image
define("WATERMARK_X",250);
// How many pixels should we move the watermark from the far most bottom of the image
define("WATERMARK_Y",40);

/*==================================================================================================*/

// Extension support
define("ZLIB", extension_loaded('zlib'));
define("APC_SUPPORT", extension_loaded('apc'));
define("MEMCACHE_SUPPORT", extension_loaded('memcache'));

// MEMCACHE
global $mc;
if (MEMCACHE_SUPPORT && USE_MEMCACHE) {
	$mc = new Memcache;
	$mc->connect(MEMCACHE_PATH, 0);
}

// Get unique key
if (APC_SUPPORT || MEMCACHE_SUPPORT)
	define("CACHE_KEY", md5(str_replace(array("&clear_cache=1", "&output=0"), array("", ""), $_SERVER['REQUEST_URI'])));
else
	define("CACHE_KEY", '');

if(isset($_GET['no_cache']) && $_GET['no_cache'] == 1) {
	header("Pragma: public");
	header("Expires: 0");
	header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
} else {
	header('Cache-Control: max-age=' . (60 * 60 * 24 * 365));
	header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', mktime(0, 0, 0, 0, 1, date("Y")+1)));
}
header('Accept-Encoding: gzip, deflate');

// Check if we already have it on the client side
if (CACHE302) {
	if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
		header('HTTP/1.1 304 Not Modified');
		die();
	}
}

if(!isset($_GET['no_cache']) || (isset($_GET['no_cache']) && $_GET['no_cache'] != 1	)) {
	header('Last-Modified: ' . gmdate('D, d M Y H:i:s \G\M\T', mktime(0, 0, 0, 0, 1, date("Y"))));
}

// Delete cache image
if (isset($_GET['clear_cache']) && $_GET['clear_cache'] == 1) {
	if (APC_SUPPORT) {
		apc_delete(CACHE_KEY);
		apc_delete(CACHE_KEY . 'content-type');
	}
	if (MEMCACHE_SUPPORT) {
		if (!is_object($mc) || !($mc instanceof Memcache)) {
			$mc = new Memcache;
			$mc->connect(MEMCACHE_PATH, 0);
		}
		$mc->delete(CACHE_KEY);
		$mc->delete(CACHE_KEY . 'content-type');
	}
	@rrmdir('img_cache/' . $_GET['id']);
	if (isset($_GET['output']) && $_GET['output'] == 0) {
		die('Cleared Cache');
	}
}

if (APC_SUPPORT && USE_APC) {
	$content = apc_fetch(CACHE_KEY);
	$content_type = apc_fetch(CACHE_KEY . 'content-type');
	if ($content !== FALSE && $content_type !== FALSE) {
		header($content_type);
		die($content);
	}
}
if (MEMCACHE_SUPPORT && USE_MEMCACHE) {
	$content = $mc->get(CACHE_KEY);
	$content_type = $mc->get(CACHE_KEY . 'content-type');
	if ($content !== FALSE && $content_type !== FALSE) {
		header($content_type);
		die($content);
	}
}

// Going DEFCON 1 in 3... 2... 1...
ob_start(STREAM_HANDLER);

/**
 *
 * @convert BMP to GD
 *
 * @param string $src
 *
 * @param string|bool $dest
 *
 * @return bool
 *
 */
function bmp2gd($src, $dest = false) {
	/*** try to open the file for reading ***/
	if (!($src_f = fopen($src, "rb"))) {
		return false;
	}

	/*** try to open the destination file for writing ***/
	if (!($dest_f = fopen($dest, "wb"))) {
		return false;
	}

	/*** grab the header ***/
	$header = unpack("vtype/Vsize/v2reserved/Voffset", fread($src_f, 14));

	/*** grab the rest of the image ***/
	$info = unpack("Vsize/Vwidth/Vheight/vplanes/vbits/Vcompression/Vimagesize/Vxres/Vyres/Vncolor/Vimportant",
				   fread($src_f, 40));

	/*** extract the header and info into varibles ***/
	extract($info);
	extract($header);

	/*** check for BMP signature ***/
	if ($type != 0x4D42) {
		return false;
	}

	/*** set the pallete ***/
	$palette_size = $offset - 54;
	$ncolor = $palette_size / 4;
	$gd_header = "";

	/*** true-color vs. palette ***/
	$gd_header .= ($palette_size == 0) ? "\xFF\xFE" : "\xFF\xFF";
	$gd_header .= pack("n2", $width, $height);
	$gd_header .= ($palette_size == 0) ? "\x01" : "\x00";
	if ($palette_size) {
		$gd_header .= pack("n", $ncolor);
	}
	/*** we do not allow transparency ***/
	$gd_header .= "\xFF\xFF\xFF\xFF";

	/*** write the destination headers ***/
	fwrite($dest_f, $gd_header);

	/*** if we have a valid palette ***/
	if ($palette_size) {
		/*** read the palette ***/
		$palette = fread($src_f, $palette_size);
		/*** begin the gd palette ***/
		$gd_palette = "";
		$j = 0;
		/*** loop of the palette ***/
		while ($j < $palette_size)
		{
			$b = $palette{$j++};
			$g = $palette{$j++};
			$r = $palette{$j++};
			$a = $palette{$j++};
			/*** assemble the gd palette ***/
			$gd_palette .= "$r$g$b$a";
		}
		/*** finish the palette ***/
		$gd_palette .= str_repeat("\x00\x00\x00\x00", 256 - $ncolor);
		/*** write the gd palette ***/
		fwrite($dest_f, $gd_palette);
	}

	/*** scan line size and alignment ***/
	$scan_line_size = (($bits * $width) + 7) >> 3;
	$scan_line_align = ($scan_line_size & 0x03) ? 4 - ($scan_line_size & 0x03) : 0;

	/*** this is where the work is done ***/
	for ($i = 0, $l = $height - 1; $i < $height; $i++, $l--)
	{
		/*** create scan lines starting from bottom ***/
		fseek($src_f, $offset + (($scan_line_size + $scan_line_align) * $l));
		$scan_line = fread($src_f, $scan_line_size);
		if ($bits == 24) {
			$gd_scan_line = "";
			$j = 0;
			while ($j < $scan_line_size)
			{
				$b = $scan_line{$j++};
				$g = $scan_line{$j++};
				$r = $scan_line{$j++};
				$gd_scan_line .= "\x00$r$g$b";
			}
		}
		elseif ($bits == 8)
		{
			$gd_scan_line = $scan_line;
		}
		elseif ($bits == 4)
		{
			$gd_scan_line = "";
			$j = 0;
			while ($j < $scan_line_size)
			{
				$byte = ord($scan_line{$j++});
				$p1 = chr($byte >> 4);
				$p2 = chr($byte & 0x0F);
				$gd_scan_line .= "$p1$p2";
			}
			$gd_scan_line = substr($gd_scan_line, 0, $width);
		}
		elseif ($bits == 1)
		{
			$gd_scan_line = "";
			$j = 0;
			while ($j < $scan_line_size)
			{
				$byte = ord($scan_line{$j++});
				$p1 = chr((int)(($byte & 0x80) != 0));
				$p2 = chr((int)(($byte & 0x40) != 0));
				$p3 = chr((int)(($byte & 0x20) != 0));
				$p4 = chr((int)(($byte & 0x10) != 0));
				$p5 = chr((int)(($byte & 0x08) != 0));
				$p6 = chr((int)(($byte & 0x04) != 0));
				$p7 = chr((int)(($byte & 0x02) != 0));
				$p8 = chr((int)(($byte & 0x01) != 0));
				$gd_scan_line .= "$p1$p2$p3$p4$p5$p6$p7$p8";
			}
			/*** put the gd scan lines together ***/
			$gd_scan_line = substr($gd_scan_line, 0, $width);
		}
		/*** write the gd scan lines ***/
		fwrite($dest_f, $gd_scan_line);
	}
	/*** close the source file ***/
	fclose($src_f);
	/*** close the destination file ***/
	fclose($dest_f);

	return true;
}

/**
 *
 * @ceate a BMP image
 *
 * @param string $filename
 *
 * @return bin string on success
 *
 * @return bool false on failure
 *
 */
function ImageCreateFromBmp($filename) {
	/*** create a temp file ***/
	$tmp_name = tempnam("/tmp", "GD");
	/*** convert to gd ***/
	if (bmp2gd($filename, $tmp_name)) {
		/*** create new image ***/
		$img = imagecreatefromgd($tmp_name);
		/*** remove temp file ***/
		unlink($tmp_name);
		/*** return the image ***/
		return $img;
	}
	return false;
}

class cropImage {
	// Initialize variables;
	var $imgSrc, $myImage, $cropHeight, $cropWidth, $x, $y, $thumb, $dif;

	// Watermark
	private $watermark = WATERMARK;

	/**
	 * Stage 2: Read the image and check if it is present on our cache folder. If so we'll just use the cached version. Take in account that even if you supply
	 * an image on an external source it will not check the image itself but rather the link, thus, no external connection is made.
	 *
	 * Also check what type of file we're working with. Different files, different methods.
	 *
	 * @param $image The image that it's to crop&scale
	 * @return nothing
	 */
	function setImage($image) {

		// Your Image
		$this->imgSrc = $image;
		// Getting the image dimensions
		list ($width, $height) = getimagesize($this->imgSrc);
		// Check what file we're working with
		if ($this->getExtension($this->imgSrc) == 'png') {
			//create image png
			$this->myImage = imagecreatefrompng($this->imgSrc) or die ("Error: Cannot find image!");
			imagealphablending($this->myImage, true); // setting alpha blending on
			imagesavealpha($this->myImage, true); // save alphablending setting (important)
		} elseif ($this->getExtension($this->imgSrc) == 'jpg' || $this->getExtension($this->imgSrc) == 'jpeg' || $this->getExtension($this->imgSrc) == 'jpe') {
			//create image jpeg
			$this->myImage = imagecreatefromjpeg($this->imgSrc) or die ("Error: Cannot find image!");
		} elseif ($this->getExtension($this->imgSrc) == 'gif') {
			//create image gif
			$this->myImage = imagecreatefromgif($this->imgSrc) or die ("Error: Cannot find image!");
		} elseif ($this->getExtension($this->imgSrc) == 'bmp') {
			//create image gif
			$this->myImage = ImageCreateFromBmp($this->imgSrc) or die ("Error: Cannot find image!");
		}

		// Find biggest length
		if ($width > $height)
			$biggestSide = $width;
		else
			$biggestSide = $height;

		// This will zoom in to 50% zoom (crop!)
		$cropPercent = 1;
		// Get the size that you submitted for resize on the URL
		$both_sizes = explode("x", $_GET ['size']);

		// Check if it was submited something like 50x50 and not only 50 (wich is also supported)
		if (!empty($_GET['size'])) {
			if (count($both_sizes) == 2) {
				if ($width > $height) {
					// Apply the cropping formula
					$this->cropHeight = $biggestSide * (($both_sizes [1] * $cropPercent) / $both_sizes [0]);
					$this->cropWidth = $biggestSide * $cropPercent;
				} else {
					// Apply the cropping formula
					$this->cropHeight = $biggestSide * $cropPercent;
					$this->cropWidth = $biggestSide * (($both_sizes [0] * $cropPercent) / $both_sizes [1]);
				}
			} else {
				$this->cropHeight = $biggestSide * $cropPercent;
				$this->cropWidth = $biggestSide * $cropPercent;
			}
		} else {
			if ($width > $height) {
				// Apply the cropping formula
				$this->cropHeight = $biggestSide * ($height * $cropPercent) / $width;
				$this->cropWidth = $biggestSide * $cropPercent;
			} else {
				// Apply the cropping formula
				$this->cropHeight = $biggestSide * $cropPercent;
				$this->cropWidth = $biggestSide * ($width * $cropPercent) / $height;
			}
		}

		// Getting the top left coordinate
		$this->x = ($width - $this->cropWidth) / 2;
		$this->y = ($height - $this->cropHeight) / 2;

	}

	/**
	 * From a file get the extension
	 *
	 * @param $filename The filename
	 * @return string file extension
	 */
	function cache_url($filename) {
		global $size_string;

		$array = explode("/", $size_string);
		$tmp = 'img_cache';

		foreach ($array as $element) {
			if (!is_dir($tmp . '/' . $element)) {
				mkdir($tmp . '/' . $element);
			}
			$tmp = $tmp . '/' . $element;
		}

		return 'img_cache/' . $size_string . '/' . $filename;
	}

	/**
	 * From a file get the extension
	 *
	 * @param $filename The filename
	 * @return string file extension
	 */
	function getExtension($filename) {
		return $ext = strtolower(array_pop(explode('.', $filename)));
	}

	/**
	 * Add a watermark to the image
	 *
	 * @param $filename The filename
	 * @return string file extension
	 */
	function addWatermark(&$image)
	{
		global $imagem_encontrada;
		if (is_resource($image) && $imagem_encontrada) {
			if(!isset($_GET['size']) || empty($_GET['size'])) {
				$thumbSizex = $this->cropWidth;
				$thumbSizey = $this->cropHeight;
			} else {
				$thumbSizex = $thumbSizey = $_GET ['size'];
				$both_sizes = explode("x", $_GET ['size']);
				if (count($both_sizes) == 2) {
					$thumbSizex = $both_sizes [0];
					$thumbSizey = $both_sizes [1];
				}
			}
			$watermark = imagecreatefrompng($this->watermark) or die ("Error: Cannot find watermark image!");
			list($watermark_width, $watermark_height, $type, $attr) = getimagesize($this->watermark);
			if ($thumbSizey >= $watermark_height * 1 && $thumbSizex >= $watermark_width * 1) {
				imagecopy($image, $watermark, $thumbSizex - WATERMARK_X, $thumbSizey - WATERMARK_Y, 0, 0, $watermark_width, $watermark_height);
			} else {}
			imagedestroy($watermark);
		} else {
			return false;
		}
	}

	/**
	 * For PNG files (and possibly GIF) add transparency filter
	 *
	 * @param $new_image
	 * @param $image_source
	 * @return nothing
	 */
	function setTransparency($new_image, $image_source) {
		$transparencyIndex = imagecolortransparent($image_source);
		$transparencyColor = array('red' => 255, 'green' => 255, 'blue' => 255);

		if ($transparencyIndex >= 0) {
			$transparencyColor = imagecolorsforindex($image_source, $transparencyIndex);
		}

		$transparencyIndex = imagecolorallocate($new_image, $transparencyColor ['red'], $transparencyColor ['green'], $transparencyColor ['blue']);
		imagefill($new_image, 0, 0, $transparencyIndex);
		imagecolortransparent($new_image, $transparencyIndex);
	}

	/**
	 * Stage 3: Apply the changes and create image resource (new one).
	 *
	 * @return nothing
	 */
	function createThumb()
	{
		if(!isset($_GET['size']) || empty($_GET['size'])) {
			$thumbSizex = $this->cropWidth;
			$thumbSizey = $this->cropHeight;
		} else {
			$thumbSizex = $thumbSizey = $_GET ['size'];
			$both_sizes = explode("x", $_GET ['size']);
			if (count($both_sizes) == 2) {
				$thumbSizex = $both_sizes [0];
				$thumbSizey = $both_sizes [1];
			}
		}

		$this->thumb = imagecreatetruecolor($thumbSizex, $thumbSizey);
		$bg = imagecolorallocate($this->thumb, 255, 255, 255);
		imagefill($this->thumb, 0, 0, $bg);
		imagecopyresampled($this->thumb, $this->myImage, 0, 0, $this->x, $this->y, $thumbSizex, $thumbSizey, $this->cropWidth, $this->cropHeight);
		if (($this->getExtension($this->imgSrc) == 'png' || $this->getExtension($this->imgSrc) == 'gif') && isset ($_GET ['transparent']) && $_GET ['transparent'] == 1) {
			$this->setTransparency($this->thumb, $this->myImage);
		}
	}

	/**
	 * Stage 4: Save image in cache and return the new image.
	 *
	 * @return nothing
	 */
	function renderImage() {
		global $size_string;

		$image_created = "";
		
		// Check if we should use watermark
		if(USE_WATERMARK)
			$this->addWatermark($this->thumb);
			
		if ($this->getExtension($this->imgSrc) == 'png') {
			header('Content-type: image/png');
			imagepng($this->thumb, null, PNG_IMAGE_QUALITY);
			/**
			 * Save image to the cache folder
			 */
			if (USE_CACHE)
				imagepng($this->thumb, $this->cache_url(basename($this->imgSrc)), 0);
		} elseif ($this->getExtension($this->imgSrc) == 'jpg' || $this->getExtension($this->imgSrc) == 'jpeg' || $this->getExtension($this->imgSrc) == 'jpe') {
			header('Content-type: image/jpeg');
			imagejpeg($this->thumb, null, IMAGE_QUALITY);
			/**
			 * Save image to the cache folder
			 */
			if (USE_CACHE)
				imagejpeg($this->thumb, $this->cache_url(basename($this->imgSrc)), IMAGE_QUALITY);
		} elseif ($this->getExtension($this->imgSrc) == 'gif') {
			header('Content-type: image/gif');
			imagegif($this->thumb);
			/**
			 * Save image to the cache folder
			 */
			if (USE_CACHE)
				imagegif($this->thumb, $this->cache_url(basename($this->imgSrc)));
		} elseif ($this->getExtension($this->imgSrc) == 'bmp') {
			header('Content-type: image/jpeg');
			imagejpeg($this->thumb, null, IMAGE_QUALITY);
			/**
			 * Save image to the cache folder
			 */
			if (USE_CACHE)
				imagejpeg($this->thumb, $this->cache_url(basename($this->imgSrc)), IMAGE_QUALITY);
		}
		imagedestroy($this->thumb);
	}
}

function rrmdir($dir) {
	if (is_dir($dir)) {
		$objects = scandir($dir);
		foreach ($objects as $object) {
			if ($object != "." && $object != "..") {
				if (filetype($dir . "/" . $object) == "dir")
					rrmdir($dir . "/" . $object); else unlink($dir . "/" . $object);
			}
		}
		reset($objects);
		rmdir($dir);
	}
}

// Some variables needed for this to work. We set $size_string as global in order to access it on renderImage()
global $size_string, $imagem_encontrada;
if (!isset($_GET['src']))
	$_GET['src'] = "";
$_GET['src'] = urldecode($_GET['src']);

// Initialize our Crop Image class
$image = new cropImage ();

$size_string = "";

if (!empty($_GET['size'])) {
	$both_sizes = explode("x", $_GET ['size']);
	if (count($both_sizes) == 2) {
		$size_string = $both_sizes [0] . "x" . $both_sizes [1];
	} else if (!empty($_GET['size'])) {
		$size_string = $_GET ['size'] . "x" . $_GET ['size'];
	}
} else
	$size_string = 'original';

// Anti-Duplicate Mechanism
if (isset($_GET['id'])) {
	$size_string = $_GET['id'] . '/' . $size_string;
} else {
	$size_string = $size_string . session_id();
}

$imagem_encontrada = true;

// Check if image exists, if not add the default
clearstatcache();
if (!is_file($_GET ['src']) || empty($_GET ['src'])) {
	$nao_encontrada = false;
	if ($both_sizes[0] <= 90 || $both_sizes[1] <= 70)
		$_GET ['src'] = "img/sem_foto_small.jpeg";
	else
		$_GET ['src'] = "img/sem_foto_big.jpeg";
} elseif ($_GET['src'] == "img/sem_foto_small.jpeg" || $_GET ['src'] == "img/sem_foto_big.jpeg") {
	$nao_encontrada = false;
}

/**
 * Atempt to load our cached image. If we can't that means there is no cache for that image. If we find
 * we'll just load that one adn won't even think about cropping and scaling.
 *
 * Stage 1: Read the image and check if it is present on our cache folder. If so weÍll just use the cached version.
 * Take in account that even if you supply an image on an external source it will not check the image itself but rather the link, thus, no external connection is made.
 */

if (USE_CACHE)
	$img_cached = $image->cache_url(basename($_GET ['src']));
else
	$img_cached = "";

// This is local storage cache. If you don't have APC nor MEMCACHE this is how you should cache your files
if (file_exists($img_cached)) {
	if ($image->getExtension($img_cached) == 'png') {
		if (MEMORY_METHOD) {
			$myImage = imagecreatefrompng($img_cached) or die ("Error: Cannot find image!");
			header('Content-type: image/png');
			imagepng($myImage, null, PNG_IMAGE_QUALITY);
		} else {
			header('Content-type: image/png');
			echo file_get_contents($img_cached);
		}
	} elseif ($image->getExtension($img_cached) == 'jpg' || $image->getExtension($img_cached) == 'jpeg' || $image->getExtension($img_cached) == 'jpe') {
		if (MEMORY_METHOD) {
			$myImage = imagecreatefromjpeg($img_cached) or die ("Error: Cannot find image!");
			header('Content-type: image/jpeg');
			imagejpeg($myImage, null, IMAGE_QUALITY);
		} else {
			header('Content-type: image/jpeg');
			echo file_get_contents($img_cached);
		}
	} elseif ($image->getExtension($img_cached) == 'gif') {
		if (MEMORY_METHOD) {
			$myImage = imagecreatefromgif($img_cached) or die ("Error: Cannot find image!");
			header('Content-type: image/gif');
			imagegif($myImage);
		} else {
			header('Content-type: image/gif');
			echo file_get_contents($img_cached);
		}
	} elseif ($image->getExtension($img_cached) == 'bmp') {
		if (MEMORY_METHOD) {
			$myImage = imagecreatefromjpeg($img_cached) or die ("Error: Cannot find image!");
			header('Content-type: image/jpeg');
			imagejpeg($myImage, null, IMAGE_QUALITY);
		} else {
			header('Content-type: image/jpeg');
			echo file_get_contents($img_cached);
		}
	}

	imagedestroy($myImage);
} else {
	$image->setImage($_GET ['src']);
	$image->createThumb();
	$image->renderImage();
}

if (APC_SUPPORT && USE_APC) {
	// Add content to your APC variable
	apc_add(CACHE_KEY, ob_get_contents());
	// Add content type to your APC variable
	apc_add(CACHE_KEY . 'content-type', end(headers_list()));
}
if (MEMCACHE_SUPPORT && USE_MEMCACHE) {
	$content = ob_get_contents();
	$content_type = end(headers_list());
	// Add content to your Memcache variable
	$mc->add(CACHE_KEY, $content, is_bool($content) || is_int($content) || is_float($content) ? false : MEMCACHE_COMPRESSED);
	// Add content type to your Memcache variable
	$mc->add(CACHE_KEY . 'content-type', $content_type, is_bool($content_type) || is_int($content_type) || is_float($content_type) ? false : MEMCACHE_COMPRESSED);
}
// Send content to the browser
ob_end_flush();
?>