<?php
require_once(TESTS . 'app_test_case.php');
class AssetTest extends AppTestCase {
	function setUp() {
		App::import('Helper', 'Assets.Asset');
		$this->Sut = new AssetHelper();

		$this->singlePackage = array(
			TESTS_TMP . 'test.csv'
		);

		$this->multiPackage = array(
			TESTS_TMP . 'test.csv',
			TESTS_TMP . 'test.csv2'
		);
	}

	function startTest() {
		parent::startTest();
		$this->Sut->reset();
		foreach ($this->multiPackage as $file) {
			touch($file);
		}
	}

	function endTest($method) {
		parent::endTest($method);
		foreach ($this->multiPackage as $file) {
			unlink($file);
		}
	}

	function testConcatFileMtime() {
		$result = $this->Sut->_concatFileMtime($this->singlePackage);
		$expected = md5(filemtime($this->singlePackage[0]));
		$this->assertEqual($expected, $result);

		$expected = md5(filemtime($this->multiPackage[0]) . filemtime($this->multiPackage[1]));
		$result = $this->Sut->_concatFileMtime($this->multiPackage);
		$this->assertEqual($expected, $result);
	}

	function testConcatFilename() {
		$result = $this->Sut->_concatFileNames($this->singlePackage);
		$expected = md5($this->singlePackage[0]);
		$this->assertEqual($expected, $result);

		$result = $this->Sut->_concatFileNames($this->multiPackage);
		$expected = md5($this->multiPackage[0] . $this->multiPackage[1]);
		$this->assertEqual($expected, $result);
	}

	function testCleanDir() {
		$files = glob(TESTS_TMP . DS . '*');
		$fileNames = $this->Sut->_concatFileNames($this->multiPackage);

		$files = $this->_removeEmptyFile($files);
		$this->assertEqual(count($this->multiPackage), count($files));

		// test clean dir only removes files that start with the $fileNames string
		$this->Sut->_cleanDir(TESTS_TMP, $fileNames);
		$this->assertEqual(count($this->multiPackage), count($files));

		// create a file that starts with that string, make sure it gets deleted
		$someNewFile = TESTS_TMP . $fileNames . '_' . time() . 'csv';
		touch($someNewFile);
		$files = glob(TESTS_TMP . DS . '*');
		$files = $this->_removeEmptyFile($files);
		$this->assertEqual(count($this->multiPackage) + 1, count($files));

		$this->Sut->_cleanDir(TESTS_TMP, $fileNames);
		$files = glob(TESTS_TMP . DS . '*');
		$files = $this->_removeEmptyFile($files);
		$this->assertEqual(count($this->multiPackage), count($files));
	}

	function _removeEmptyFile($files) {
		foreach ($files as $key => $file) {
			if (strpos($file, 'empty') !== false) {
				unset($files[$key]);
			}
		}
		return $files;
	}

	function testConvertCssPaths() {
		$css = <<<CSS
		#someSelector {
			background: url(img/some_image.jpg) no-repeat;
		}
		#someOtherSelector {
			background: url(../../img/some_image.jpg) no-repeat;
		}
CSS;

		$expected = <<<CSS
		#someSelector {
			background: url(../img/some_image.jpg) no-repeat;
		}
		#someOtherSelector {
			background: url(../../../img/some_image.jpg) no-repeat;
		}
CSS;

		$result = $this->Sut->_convertCssPaths($css);
		$this->assertEqual($result, $expected);
	}

	function testConvertLessToCss() {
		$less = <<<LESS
.faq {
  margin: 40px 0 0 0;
  h3{
    font-size: 22px;
    font-weight: normal;
    margin-bottom:0;
  }
  p {
    font-size: 14px;
    margin-bottom: 1em;
  }
}
LESS;

		$expected = <<<CSS
.faq {
  margin: 40px 0 0 0;
}
.faq h3 {
  font-size: 22px;
  font-weight: normal;
  margin-bottom: 0;
}
.faq p {
  font-size: 14px;
  margin-bottom: 1em;
}
CSS;

		$result = $this->Sut->_less($less);
		$this->assertEqual($result, $expected);
	}

	function testConvertCoffeescriptToJs() {
		$coffee = 'cubes = (math.cube num for num in list)';
		$expected = <<<JS
(function() {
  var cubes, num;
  cubes = (function() {
    var _i, _len, _results;
    _results = [];
    for (_i = 0, _len = list.length; _i < _len; _i++) {
      num = list[_i];
      _results.push(math.cube(num));
    }
    return _results;
  })();
}).call(this);
JS;

		$this->Sut->settings['js']['preprocessor'] = array(
			'method' => 'coffeescript',
			'ext' => 'coffee'
		);
		$result = $this->Sut->_coffeescript($coffee);
		$this->assertEqual($result, $expected);
	}

	function testConvertKaffeineToJs() {
		$kaffeine = 'open door';
		$expected = <<<JS
open(door)
JS;

		$this->Sut->settings['js']['preprocessor'] = array(
			'method' => 'kaffeine',
			'ext' => 'k'
		);
		$result = $this->Sut->_kaffeine($kaffeine);

		$this->assertEqual($result, $expected);
	}

	function testMinifyCss() {
		$css = <<<CSS
		.faq {
			margin: 40px 0 0 0;
		}
CSS;

		$expected = ".faq{margin:40px\n0 0 0}";
		$this->assertEqual($this->Sut->_cssmin($css), $expected);
	}

	function testJsmin() {
		$js = <<<JS
		$(function() {
		  $('.js_prettyjson').each(function() {});
		});
JS;

		$expected = "\n\$(function(){\$('.js_prettyjson').each(function(){});});";
		$result = $this->Sut->_jsmin($js);
		$this->assertEqual($result, $expected);
	}

	function testUglifyjs() {
		$js = <<<JS
		$(function() {
		  $('.js_prettyjson').each(function() {});
		});
JS;

		$expected = "\$(function(){\$(\".js_prettyjson\").each(function(){})})";
		$result = $this->Sut->_uglifyjs($js);

		$this->assertEqual($result, $expected);
	}

	function testFetchContentFromPackage() {
		$delimiter = ";\n\n";
		foreach ($this->multiPackage as $file) {
			$content = array_sum(explode(' ', microtime()));
			file_put_contents($file, $content);
		}

		$result = $this->Sut->_fetchContentFromPackage($this->multiPackage, 'js', $delimiter);

		$expected = '';
		foreach ($this->multiPackage as $i => $file) {
			// since this is a js package, minification adds an extra \n at the start
			// $expected .= "\n";
			$expected .= file_get_contents($file);

			if ($i < count($this->multiPackage) - 1) {
				$expected .= $delimiter;
			}
		}

		$this->assertEqual(trim($result), trim($expected));
	}
}