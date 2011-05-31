<?php
/**
 * AssetHelper class
 *
 * Manages packaged, minified inclusion for css and javascript files.
 * Also handles external scripts gracefully.
 *
 * @package default
 * @author Tim Koschuetzki, Property of Debuggable Ltd., http://debuggable.com
 */
class AssetHelper extends AppHelper {
	var $helpers = array('Common', 'Html');
	var $_fileFetchingCache = array();
	var $_resultCache = array();
	var $_lessVars = '';
	var $settings = array(
		'css' => array(
			'path' => CSS,
			'preConvertExt' => 'less',
			'ext' => 'css',
			'delim' => "\n\n",
			'lessConversionPerFile' => false,
			'mixins_file' => '',
			'minification_engine' => array(
				'method' => 'cssmin',
				'per_file' => false
			)
		),
		'js' => array(
			'path' => JS,
			'preConvertExt' => 'js',
			'ext' => 'js',
			'delim' => ";\n\n",
			'coffeeConversionPerFile' => false,
			'js_i18n' => true,
			'minification_engine' => array(
				'method' => 'jsmin',
				'per_file' => true
			)
		),
		'cleanDir' => true,
		'minify' => true,
		'packaging' => true,
		'auto_include_paths' => array(
			':path:views/layouts/:layout:',
			':path:views/:controller:/:action:',
			':path:views/:controller:/:action:_:pass:'
		)
	);
	var $params = array();
	var $lang = null;
	var $pathToNode = '/usr/local/bin/node';
/**
 * Builds the packaged and minified asset file for a given $package with $settings.
 *
 * @param string $package 
 * @param string $type 
 * @param bool $out If true, <script> or <link> tags are printed.
 * @return The path to the packaged filename.
 * @author Tim Koschuetzki
 */
	function includeFiles($package, $settings, $out = true) {
		$this->settings = Set::merge($this->settings, $settings);
		extract($this->settings);
		$opts = $this->settings[$type];

		if (!class_exists('ShellDispatcher')) {
			$View = ClassRegistry::getObject('view');
			$this->params = $View->params;
		} elseif (isset($params)) {
			$this->params = $params;
		}

		if (isset($View->layouts)) {
			$this->layouts = $View->layouts;
		} else if (isset($View)) {
			$this->layouts = array($View->layout);
		} elseif (isset($layout)) {
			$this->layouts = array($layout);
		}

		if (!isset($lang) && defined('LANG')) {
			$lang = LANG;
		}
		if (isset($lang)) {
			$this->lang = $lang;
		}

		$files = $this->_filesToInclude($package, $opts, $type);
		$includes = $files[0];
		$externals = $files[1];

		// check to build cache
		$settings = $this->settings;
		unset($settings['params'], $settings['cleanDir'], $settings['layout']);
		$key = md5(json_encode($settings)) . md5(json_encode($includes));

		if (!isset($this->_resultCache[$key])) {
			if ($this->settings['packaging']) {
				$this->_resultCache[$key] = $this->_packaged($includes, $type, $out);
			} else {
				$this->_resultCache[$key] = $this->_nonPackaged($includes, $type, $out);
			}
		}
		$result = $this->_resultCache[$key];

		if ($out) {
			if (!isset($this->Html)) {
				App::import('Helper', 'Html');
				$this->Html = new HtmlHelper();
			}

			if ($type == 'js') {
				foreach ($externals as $file) {
					echo $this->Html->script($file);
				}
			}

			if ($type == 'css') {
				foreach ($externals as $file) {
					echo $this->Html->css($file);
				}
			}
		}

		return $result;
	}
/**
 * Resets the helper's internal cache system.
 *
 * @return void
 * @author Tim Koschuetzki
 */
	function reset() {
		$this->_fileFetchingCache = array();
		$this->_resultCache = array();
		$this->_lessVars = '';
	}
/**
 * Adds javascript specific to a view to the global registry to be embedded in the footer,
 * independent of the combined file we are building.
 *
 * @param string $js 
 * @return void
 * @author Tim Koschuetzki
 */
	function addPageJavascript($js) {
		$key = 'page_javascript';
		$value = Configure::read($key);
		$value .= "\n\n" . $js;
		Configure::write($key, $value);
		return $value;
	}
/**
 * undocumented function
 *
 * @param string $package 
 * @return void
 * @author Tim Koschuetzki
 */
	function _filesToInclude($package, $opts, $type) {
		$includes = $externals = array();
		$controller = Inflector::camelize(@$this->params['controller']);
		$action = @$this->params['action'];

		foreach ($package as $include => $rules) {
			if ($this->_isAllowed($controller, $action, $rules)) {
				if (strpos($include, '://') === false && strpos($include, '//') !== 0) {
					if (strpos($include, '/') !== 0) {
						$include = $opts['path'] . $include;
					}
					$includes[] = $include;
				} else {
					$externals[] = $include;
				}
			}
		}

		$includes = am($includes, $this->_autoIncludePaths($type));
		return array($includes, $externals);
	}
/**
 * undocumented function
 *
 * @param string $includes 
 * @param string $externals 
 * @param string $type 
 * @param string $out 
 * @return void
 * @author Tim Koschuetzki
 */
	function _nonPackaged($includes, $type, $out) {
		$opts = $this->settings[$type];
		$result = array();

		// if there is a configured mixins file to load variables, mixins, etc. load its contents
		if ($type === 'css' && !empty($opts['mixins_file']) && empty($this->_lessVars)) {
			foreach ($includes as $include) {
				if (basename($include) == $opts['mixins_file']) {
					$this->_lessVars = file_get_contents($include);
					break;
				}
			}
		}

		foreach ($includes as $include) {
			$content = '';
			if ($type === 'css' && !empty($this->_lessVars)) {
				$content .= $this->_lessVars . "\n\n";
			}
			$content .= file_get_contents($include);

			if ($type === 'js') {
				if ($this->lang && strpos($content, $opts['js_i18n']) !== false) {
					$content = $this->_parseJsTranslations($content);
				}

				$ext = explode('.', $include);
				$ext = array_pop($ext);
				if ($ext == $opts['preConvertExt']) {
					$content = $this->_convertCoffeeScriptToJs($content);
				}
			}

			if ($type === 'css') {
				$content = $this->_convertCssPaths($content);
				$content = $this->_convertLessToCss($content);
			}

			$file = r(array('.' . $opts['preConvertExt'], '.' . $opts['ext']), '', basename($include));
			$file = $opts['path'] . 'aggregate' . DS . $file . '.' . $opts['ext'];

			file_put_contents($file, $content);
			@chmod($file, 0664);
			@chown($file, 'www-data.www-data');
			$result[] = $file;
		}

		if (!$out) {
			return $result;
		}

		foreach ($result as $file) {
			$file = r($opts['path'], '', $file);
			if ($type == 'js') {
				echo $this->Html->script($file);
			}
			if ($type == 'css') {
				echo $this->Html->css($file);
			}
		}
	}
/**
 * undocumented function
 *
 * @return void
 * @author Tim Koschuetzki
 */
	function _packaged($includes, $type, $out) {
		$fileName = $this->_buildFileForPackage($includes, $type);

		if (!$out) {
			return $fileName;
		}

		if ($type == 'js') {
			echo '<script type="text/javascript" src="/js/' . $fileName . '"></script>';
		}

		if ($type == 'css') {
			echo '<link rel="stylesheet" type="text/css" href="/css/' . $fileName . '" />';
		}

		return $fileName;
	}
/**
 * Includes for example /webroot/js/views/controller_name/action.js and
 * /webroot/css/views/pages/view_pricing.less
 *
 * @param string $includes 
 * @param string $type 
 * @return void
 * @author Tim Koschuetzki
 */
	function _autoIncludePaths($type) {
		$paths = $this->settings['auto_include_paths'];
		$opts = $this->settings[$type];

		$result = array();
		foreach ($paths as $path) {
			foreach ($this->layouts as $layout) {
				$myPath = $path;

				$replace = $opts['path'];
				if (!empty($this->params['plugin'])) {
					$replace = APP . 'plugins' . DS . $this->params['plugin'] . DS . $type;
				}

				$myPath = r(':path:', $replace, $myPath);
				$myPath = r(':layout:', $layout, $myPath);

				if (isset($this->params['controller'])) {
					$myPath = r(':controller:', $this->params['controller'], $myPath);
				}
				if (isset($this->params['action'])) {
					$myPath = r(':action:', $this->params['action'], $myPath);
				}
				if (isset($this->params['pass'][0]) && preg_match('/^\w+$/', $this->params['pass'][0])) {
					$myPath = r(':pass:', $this->params['pass'][0], $myPath);
				}

				if (strpos($myPath, '.' . $opts['preConvertExt']) === false) {
					$myPath = $myPath . '.' . $opts['preConvertExt'];
				}

				if (file_exists($myPath) && !in_array($myPath, $result)) {
					$result[] = $myPath;
				}
			}
		}

		return $result;
	}
/**
 * Builds the combined, minified inclusion file for a given $package
 * of css or js files and returns the path to it.
 *
 * @param string $package 
 * @return void
 * @author Tim Koschuetzki
 */
	function _buildFileForPackage($package, $type = 'css') {
		$opts = $this->settings[$type];

		$mtimeBuffer = $this->_concatFileMtime($package);
		$fileNames = $this->_concatFileNames($package);

		$fileName = 'aggregate' . DS . $fileNames . '_' . $mtimeBuffer;

		if ($type == 'js') {
			if ($this->lang) {
 				$fileName .= '_' . $this->lang;
			}
		}
		if ($this->settings['minify']) {
			$fileName .= '.min';
		}

		$fileName .= '.' . $opts['ext'];

		if (!file_exists($opts['path'] . $fileName)) {
			if ($this->settings['cleanDir']) {
				$this->_cleanDir($opts['path'] . 'aggregate', $fileNames);
			}

			$content = $this->_fetchContentFromPackage($package, $type, $opts['delim']);

			if ($type === 'css') {
				$content = $this->_convertCssPaths($content);
				if (!$opts['lessConversionPerFile']) {
					$content = $this->_convertLessToCss($content);
				}
			}

			if ($type === 'js' && !$opts['coffeeConversionPerFile']) {
				$content = $this->_convertCoffeeScriptToJs($content);
			}

			$minifyEngine = $opts['minification_engine'];
			if ($this->settings['minify'] && !$minifyEngine['per_file']) {
				$method = '_' . $minifyEngine['method'];
				if (method_exists($this, $method)) {
					$content = $this->$method($content);
				} else {
					trigger_error('Wrong minification engine defined.');
				}
			}

			@chmod($opts['path'] . $fileName, 0664);
			@chown($opts['path'] . $fileName, 'www-data.www-data');
			file_put_contents($opts['path'] . $fileName, $content);
		}
		return $fileName;
	}
/**
 * Fetches the contents of all files from an array of files.
 * Minifies javascript if $type === 'js'. Adds $delimiter between the contents
 * of two different files.
 *
 * @param string $path 
 * @param string $type 
 * @return void
 * @author Tim Koschuetzki
 */
	function _fetchContentFromPackage($package, $type, $delimiter) {
		$opts = $this->settings[$type];

		// if there is a configured mixins file to load variables, mixins, etc. load its contents
		$prependLessVars = $type == 'css' && $opts['lessConversionPerFile'] && 
						 !empty($opts['mixins_file']) && empty($this->_lessVars);
		if ($prependLessVars) {
			foreach ($package as $include) {
				if (basename($include) == $opts['mixins_file']) {
					$this->_lessVars = file_get_contents($include);
					break;
				}
			}
		}

		$result = '';
		foreach ($package as $include) {
			if (array_key_exists($include . $this->lang, $this->_fileFetchingCache)) {
				$content = $this->_fileFetchingCache[$include . $this->lang];
			} elseif (array_key_exists($include, $this->_fileFetchingCache)) {
				$content = $this->_fileFetchingCache[$include];
			} else {
				$content = file_get_contents($include);

				if ($type === 'js') {
					if ($this->lang && strpos($content, $opts['js_i18n']) !== false) {
						$cacheKey = $include . $this->lang;
						$content = $this->_parseJsTranslations($content);
					}
					
				}

				$ext = explode('.', $include);
				$ext = array_pop($ext);

				if ($type == 'css' && $opts['lessConversionPerFile']) {
					$content = $this->_convertLessToCss($this->_lessVars . $content);
				}

				if ($type == 'js' && $opts['coffeeConversionPerFile'] && $ext == $opts['preConvertExt']) {
					$content = $this->_convertCoffeeScriptToJs($content);
				}

				$minifyEngine = $opts['minification_engine'];
				if ($this->settings['minify'] && $minifyEngine['per_file']) {
					$method = '_' . $minifyEngine['method'];
					if (method_exists($this, $method)) {
						$content = $this->$method($content);
					} else {
						trigger_error('Wrong minification engine defined.');
					}
				}

				if ($this->lang) {
					$this->_fileFetchingCache[$include . $this->lang] = $content;
				} else {
					$this->_fileFetchingCache[$include] = $content;
				}
			}
			$result .= $content . $delimiter;
		}
		return substr($result, 0, -1 * strlen($delimiter));
	}
/**
 * Change the relative folders because the combined file
 * will be saved in /css/aggregate
 *
 * @author Tim Koschuetzki
 */
	function _convertCssPaths($css) {
		$pattern = '/url\([\'"]?([^\/\'"])([^\)\'"]+)[\'"]?\)/mi';
		$replace = "url(../$1$2$3)";
		$result = preg_replace($pattern, $replace, $css);
		return $result;
	}
/**
 * Converts a given $less string into css.
 *
 * @param string $less 
 * @return void
 * @author Tim Koschuetzki
 */
	function _convertLessToCss($less) {
		$opts = $this->settings['css'];
		if ($opts['preConvertExt'] != 'less') {
			return $less;
		}

		$tmpFileName = $opts['path'] . 'aggregate' . DS . md5($less) . '.' . $opts['preConvertExt'];
		file_put_contents($tmpFileName, $less);
		@chmod($tmpFileName, 0777);

		$cmd = APP . 'plugins' . DS . 'assets' . DS . 'vendors' . DS . 'less' . DS . 'bin' . DS . 'lessc';
		$cmd = $this->pathToNode . ' ' . $cmd . ' ' . $tmpFileName;

		$out = array();
		exec($cmd, $out);
		@unlink($tmpFileName);
		return implode("\n", $out);
	}
/**
 * Converts a given coffee script string into javascript
 *
 * @param string $coffee 
 * @return void
 * @author Tim Koschuetzki
 */
	function _convertCoffeeScriptToJs($coffee) {
		$opts = $this->settings['js'];

		if ($opts['preConvertExt'] != 'coffee') {
			return $coffee;
		}

		$tmpCoffeeFile = $opts['path'] . 'aggregate' . DS . md5($coffee) . '.' . $opts['preConvertExt'];

		file_put_contents($tmpCoffeeFile, $coffee);
		@chmod($tmpCoffeeFile, 0777);

		$cmd = 'coffee -c ' . $tmpCoffeeFile;

		$err = array();
		exec($cmd, $err);
		// pr($cmd);
		// prd($err);
		$tmpJsFile = r('.' . $opts['preConvertExt'], '.js', $tmpCoffeeFile);
		$out = file_get_contents($tmpJsFile);

		if (!empty($err)) {
			$out .= 'alert("' . implode("\n", $err) . '");';
		}
		@unlink($tmpCoffeeFile);
		@unlink($tmpJsFile);

		return $out;
	}
/**
 * Minifies a given $css string.
 *
 * @param string $css 
 * @return void
 * @author Tim Koschuetzki
 */
	function _cssmin($css) {
		require_once(APP . 'plugins' . DS . 'assets' . DS . 'vendors' . DS . 'cssmin.php');
		return CssMin::process($css);
	}
/**
 * Minifies a given javascript string.
 *
 * @param string $js 
 * @return void
 * @author Tim Koschuetzki
 */
	function _jsmin($js) {
		require_once(APP . 'plugins' . DS . 'assets' . DS . 'vendors' . DS . 'jsmin.php');
		return JSMin::minify($js);
	}
/**
 * undocumented function
 *
 * @param string $js 
 * @return void
 * @author Tim Koschuetzki
 */
	function _google_closure($js) {
		$ch = curl_init('http://closure-compiler.appspot.com/compile');

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POST, 1);
		$opts = 'output_info=compiled_code&output_format=text&compilation_level=SIMPLE_OPTIMIZATIONS&js_code=' . urlencode($js);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $opts);
		$output = curl_exec($ch);
		curl_close($ch);

		return $output;
	}
/**
 * Removes all files with names starting with $buffer from $path.
 *
 * @param string $path 
 * @return void
 * @author Tim Koschuetzki
 */
	function _cleanDir($path, $buffer) {
		require_once(LIBS . 'folder.php');
		$folder = new Folder($path);
		$contents = $folder->read();
		$files = $contents[1];

		foreach ($files as $file) {
			if (strpos($file, $buffer) === 0) {
				@unlink($path . DS . $file);
			}
		}
	}
/**
 * Concats the timestamps of the modified times of the files in an array $package
 * and returns the md5 hash of the result string. This is to have a unique representation
 * of the file modified times of a set of files.
 * undocumented function
 *
 * @param string $package 
 * @return void
 * @author Tim Koschuetzki
 */
	function _concatFileMtime($package) {
		$result = '';
		foreach ($package as $file) {
			$result .= filemtime($file);
		}
		return md5($result);
	}
/**
 * Concatenates the values of an array and applies an md5 hash on the result string.
 * This is to encode an array into a unique string.
 * undocumented function
 *
 * @param string $package 
 * @return void
 * @author Tim Koschuetzki
 */
	function _concatFileNames($package) {
		return md5(implode($package));
	}
/**
 * Takes a $text and replaces all occurances of __('some string') with the proper
 * translated string.
 *
 * @param string $text the text to translate
 * @param string $lang optional target language
 * @return void
 * @author Tim Koschuetzki
 */
	function _parseJsTranslations($text) {
		$matches = array();
		$length = strlen($text) - 1;
		for ($i = 0; $i < $length; $i++) {
			if ($text{$i} == '_' && $text{$i + 1} == '_' && $text{$i + 2} == '(') {
				$match = '';
				for ($j = $i + 3; $j < $length; $j++) {
					if ($text{$j} != ')') {
						$match .= $text{$j};
					} else {
						break;
					}
				}
				$matches[] = $match;
				$i = $j;
			}
		}

		$oldLang = Configure::read('Config.language');
		Configure::write('Config.language', Configure::read('I18n.locale.' . $this->lang));

		// the matches are wrapped in single quotes or double quotes
		// we need to take care of those when replacing the strings
		foreach ($matches as $match) {
			$quote = substr($match, 0, 1);
			$matchWithoutQuotes = substr($match, 1, strlen($match) - 2);

			$translation = __($matchWithoutQuotes, true);
			$translation = r(a("'", '"'), a("\\'", '\\"'), $translation);

			$text = r('__(' . $match . ')', $quote . $translation . $quote, $text);
		}

		Configure::write('Config.language', $oldLang);

		return $text;
	}
/**
 * Checks if the given $object/$property combination fits the $rules.
 *
 * @param string $object a controller name, like 'Signups'
 * @param string $property an action name, like 'index'
 * @param string $rules a rules string, like '!*:*, Auth:master_login, Auth:login, Auth:logout'
 * @param bool $default allow by default or not
 * @return void
 * @access public
 */
	function _isAllowed($object, $property, $rules, $default = false) {
		$allowed = $default;

		preg_match_all('/\s?([^:,]+):([^,:]+)/is', $rules, $matches, PREG_SET_ORDER);

		foreach ($matches as $match) {
			list($rawMatch, $allowedObject, $allowedProperty) = $match;
			$rawMatch = trim($rawMatch);
			$allowedObject = trim($allowedObject);
			$allowedProperty = trim($allowedProperty);
			$allowedObject = r('*', '.*', $allowedObject);
			$allowedProperty = r('*', '.*', $allowedProperty);
			$negativeCondition = false;
			if (substr($allowedObject, 0, 1) == '!') {
				$allowedObject = substr($allowedObject, 1);
				$negativeCondition = true;
			}

			if (preg_match('/^'.$allowedObject.'$/i', $object) && 
				preg_match('/^'.$allowedProperty.'$/i', $property)) {
				$allowed = !$negativeCondition;
			}
		}
		return $allowed;
	}
}
?>