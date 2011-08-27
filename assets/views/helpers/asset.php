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
	var $settings = array(
		'css' => array(
			'path' => CSS,
			'ext' => 'css',
			'delim' => "\n\n",
			'preprocessor' => array(
				'method' => 'less',
				'ext' => 'less',
				'pre_include_file' => '',
				'per_file' => false
			),
			'minification' => array(
				'method' => 'cssmin',
				'per_file' => false
			)
		),
		'js' => array(
			'path' => JS,
			'ext' => 'js',
			'delim' => ";\n\n",
			'locale' => false,
			'preprocessor' => array(
				'method' => false,
				'ext' => '',
				'per_file' => false
			),
			'minification' => array(
				'method' => 'uglifyjs',
				'per_file' => false
			)
		),
		'host' => null,
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
	var $pathToNode = '/usr/local/bin/node';

	var $_fileCache = array();
	var $_resultCache = array();
	var $_preIncludeContent = '';
	var $_usePreprocessor = true;
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

		$this->_usePreprocessor = !empty($opts['preprocessor']['method']);

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

		$files = $this->_filesToInclude($package, $opts, $type);
		$includes = $files[0];
		$externals = $files[1];

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

		if (!$out) {
			return $result;
		}

		foreach ($externals as $file) {
			if ($type == 'js') {
				echo sprintf('<script type="text/javascript" src="%s"></script>', $file);
			}
			if ($type == 'css') {
				echo sprintf('<link rel="stylesheet" type="text/css" href="%s"/>', $file);
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
		$this->_fileCache = array();
		$this->_resultCache = array();
		$this->_preIncludeContent = '';
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
					if (strpos($include, 'plugins/') === 0) {
						$fileName = explode('/', $include);
						$pluginName = Inflector::camelize($fileName[1]);
						unset($fileName[0]);
						unset($fileName[1]);
						$pluginPath = App::pluginPath($pluginName);
						$include = $pluginPath . 'webroot/' . implode('/', $fileName);
					}

					if (strpos($include, '/') !== 0) {
						$include = $opts['path'] . $include;
					}

					if (file_exists($include)) {
						$includes[] = $include;
					}
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

		$this->_parsePreIncludeFile($includes, $opts);

		foreach ($includes as $include) {
			$content = '';
			if ($type === 'css' && !empty($this->_preIncludeContent)) {
				$content .= $this->_preIncludeContent . "\n\n";
			}
			$content .= file_get_contents($include);

			$ext = explode('.', $include);
			$ext = array_pop($ext);

			if ($type === 'js') {
				$content = $this->_parseJsTranslations($content);

				if ($this->_usePreprocessor && $ext == $opts['preprocessor']['ext']) {
					$method = '_' . $opts['preprocessor']['method'];
					$content = $this->{$method}($content);
				}
			}

			if ($type === 'css') {
				$content = $this->_convertCssPaths($content);
				if ($this->_usePreprocessor && $ext == $opts['preprocessor']['ext']) {
					$method = '_' . $opts['preprocessor']['method'];
					$content = $this->{$method}($content);
				}
			}

			$toRemove = array('.' . $opts['preprocessor']['ext'], '.' . $opts['ext']);
			$file = r($toRemove, '', basename($include));
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
			$src = $this->settings['host'] ? '//' . $this->settings['host'] : '';
			if ($type == 'js') {
				echo sprintf('<script type="text/javascript" src="%s/js/%s"></script>', $src, $file);
			}
			if ($type == 'css') {
				echo sprintf('<link rel="stylesheet" type="text/css" href="%s/css/%s"/>', $src, $file);
			}
		}
	}
/**
 * Fetch the contents of the pre include file if necessary.
 *
 * @param string $includes 
 * @param string $opts 
 * @return void
 * @author Tim Koschuetzki
 */
	function _parsePreIncludeFile($includes, $opts) {
		$preIncludeFile = $this->_usePreprocessor &&
						  !empty($opts['preprocessor']['pre_include_file']);
		if (!$preIncludeFile || !empty($this->_preIncludeContent)) {
			return;
		}

		foreach ($includes as $include) {
			if (basename($include) == $opts['preprocessor']['pre_include_file']) {
				$this->_preIncludeContent = file_get_contents($include);
				break;
			}
		}
	}
/**
 * Return the packaged file for the set of $includes
 *
 * @param string $includes 
 * @param string $type 
 * @param string $out 
 * @return void
 * @author Tim Koschuetzki
 */
	function _packaged($includes, $type, $out) {
		$file = $this->_buildFileForPackage($includes, $type);

		if (!$out) {
			return $file;
		}

		$src = $this->settings['host'] ? '//' . $this->settings['host'] : '';
		if ($type == 'js') {
			echo '<script type="text/javascript" src="' . $src . '/js/' . $file . '"></script>';
		}

		if ($type == 'css') {
			echo '<link rel="stylesheet" type="text/css" href="' . $src . '/css/' . $file . '" />';
		}

		return $file;
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

				$hasPreprocessorExt = strpos($myPath, '.' . $opts['preprocessor']['ext']) === false;
				if ($this->_usePreprocessor && $hasPreprocessorExt) {
					$myPath .= '.' . $opts['preprocessor']['ext'];
				} else {
					$myPath .= '.' . $opts['ext'];
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

		if ($type == 'js' && $opts['locale']) {
			$fileName .= '_' . $opts['locale'];
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

			$usePreprocessor = $this->_usePreprocessor && !$opts['preprocessor']['per_file'];
			if ($type === 'css') {
				$content = $this->_convertCssPaths($content);
				if ($usePreprocessor) {
					$method = '_' . $opts['preprocessor']['method'];
					$content = $this->{$method}($content);
				}
			}

			if ($type === 'js' && $usePreprocessor) {
				$method = '_' . $opts['preprocessor']['method'];
				$content = $this->{$method}($content);
			}

			$minifyEngine = $opts['minification'];
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

		$this->_parsePreIncludeFile($package, $opts);

		$result = '';
		foreach ($package as $include) {
			if (isset($opts['locale']) && array_key_exists($include . $opts['locale'], $this->_fileCache)) {
				$content = $this->_fileCache[$include . $opts['locale']];
			} elseif (array_key_exists($include, $this->_fileCache)) {
				$content = $this->_fileCache[$include];
			} else {
				$content = file_get_contents($include);

				if ($type === 'js') {
					if (isset($opts['locale']) && $opts['locale']) {
						$cacheKey = $include . $opts['locale'];
						$content = $this->_parseJsTranslations($content);
					}
				}

				$ext = explode('.', $include);
				$ext = array_pop($ext);

				$hasPreprocessorExt = $opts['preprocessor']['ext'] === $ext;
				$usePreprocessor = $this->_usePreprocessor && $opts['preprocessor']['per_file'];
				$usePreprocessor = $usePreprocessor && $hasPreprocessorExt;

				if ($type == 'css' && $usePreprocessor) {
					$method = '_' . $opts['preprocessor']['method'];
					$content = $this->{$method}($content);
				}

				if ($type == 'js' && $usePreprocessor) {
					$method = '_' . $opts['preprocessor']['method'];
					$content = $this->{$method}($content);
				}

				$minifyEngine = $opts['minification'];
				if ($this->settings['minify'] && $minifyEngine['per_file']) {
					$method = '_' . $minifyEngine['method'];
					if (method_exists($this, $method)) {
						$content = $this->$method($content);
					} else {
						trigger_error('Wrong minification engine defined.');
					}
				}

				if (isset($opts['locale']) && $opts['locale']) {
					$this->_fileCache[$include . $opts['locale']] = $content;
				} else {
					$this->_fileCache[$include] = $content;
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
		$pattern = '/url\([\'"]?(?!http)([^\/\'"])([^\)\'"]+)[\'"]?\)/mi';
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
	function _less($less) {
		$cmd = 'less' . DS . 'bin' . DS . 'lessc';
		return $this->_runCmdOnContent('css', $cmd, $less);
	}
/**
 * Converts a given $kaffeine string into js.
 *
 * @param string $less 
 * @return void
 * @author Tim Koschuetzki
 */
	function _kaffeine($kaffeine) {
		$cmd = 'kaffeine' . DS . 'bin' . DS . 'kaffeine -c';
		return $this->_runCmdOnContent('js', $cmd, $kaffeine);
	}
/**
 * Minifies a given javascript string using uglifyjs.
 *
 * @param string $js 
 * @return void
 * @author Tim Koschuetzki
 */
	function _uglifyjs($js) {
		$cmd = 'uglify-js' . DS . 'bin' . DS . 'uglifyjs -nc';
		return $this->_runCmdOnContent('js', $cmd, $js);
	}
/**
 * Converts a given coffee script string into javascript
 *
 * @param string $coffee 
 * @return void
 * @author Tim Koschuetzki
 */
	function _coffeescript($coffee) {
		$cmd = 'coffee-script' . DS . 'bin' . DS . 'coffee -p';
		return $this->_runCmdOnContent('js', $cmd, $coffee);
	}
/**
 * Runs the given command $cmd with the $type options (js or css) on
 * the given content
 *
 * @param string $type 
 * @param string $cmd 
 * @param string $content 
 * @return void
 * @author Tim Koschuetzki
 */
	function _runCmdOnContent($type, $cmd, $content) {
		$opts = $this->settings[$type];

		$tmpFile = $opts['path'] . 'aggregate' . DS . md5($content) . '.' . $opts['preprocessor']['ext'];

		file_put_contents($tmpFile, $content);
		@chmod($tmpFile, 0777);

		$path = APP . 'plugins' . DS . 'assets' . DS . 'vendors' . DS . 'node_modules' . DS;
		exec($this->pathToNode . ' ' . $path . $cmd . ' ' . $tmpFile, $out);
		@unlink($tmpFile);
		return trim(implode("\n", $out));
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
 * Minifies a given javascript string using uglifyjs.
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
 * @return void
 * @author Tim Koschuetzki
 */
	function _parseJsTranslations($text) {
		$opts = $this->settings['js'];
		if (!$opts['locale']) {
			return $text;
		}

		$matches = array();
		$length = strlen($text) - 1;
		for ($i = 0; $i < $length; $i++) {
			if ($text{$i} == '_' && $text{$i + 1} == '_' && $text{$i + 2} == '(') {
				$match = '';
				for ($j = $i + 3; $j < $length; $j++) {
					if ($text{$j} == ')') {
						break;
					}
					$match .= $text{$j};
				}
				$matches[] = $match;
				$i = $j;
			}
		}

		$oldLang = Configure::read('Config.language');
		Configure::write('Config.language', Configure::read('I18n.locale.' . $opts['locale']));

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