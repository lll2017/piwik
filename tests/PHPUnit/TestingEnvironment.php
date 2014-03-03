<?php

use Piwik\Piwik;
use Piwik\Config;
use Piwik\Common;
use Piwik\Session\SessionNamespace;

require_once PIWIK_INCLUDE_PATH . "/core/Config.php";

if (!defined('PIWIK_TEST_MODE')) {
    define('PIWIK_TEST_MODE', true);
}

class Piwik_MockAccess
{
    private $access;

    public function __construct($access)
    {
        $this->access = $access;
        $access->setSuperUserAccess(true);
    }

    public function __call($name, $arguments)
    {
        return call_user_func_array(array($this->access, $name), $arguments);
    }

    public function reloadAccess($auth = null)
    {
        return true;
    }

    public function getLogin()
    {
        return 'superUserLogin';
    }
}

/**
 * Sets the test environment.
 */
class Piwik_TestingEnvironment
{
    private $behaviorOverrideProperties = array();

    public function __construct()
    {
        $overridePath = PIWIK_INCLUDE_PATH . '/tmp/testingPathOverride.json';
        if (file_exists($overridePath)) {
            $this->behaviorOverrideProperties = json_decode(file_get_contents($overridePath), true);
        }
    }

    public function __get($key)
    {
        return isset($this->behaviorOverrideProperties[$key]) ? $this->behaviorOverrideProperties[$key] : null;
    }

    public function __set($key, $value)
    {
        $this->behaviorOverrideProperties[$key] = $value;
    }

    public function save()
    {
        $overridePath = PIWIK_INCLUDE_PATH . '/tmp/testingPathOverride.json';
        file_put_contents($overridePath, json_encode($this->behaviorOverrideProperties));
    }

    public function delete()
    {
        $this->behaviorOverrideProperties = array();
        $this->save();
    }

    public function logVariables()
    {
        \Piwik\Log::verbose("Test Environment Variables: %s", print_r($this->behaviorOverrideProperties, true));
    }

    public static function addHooks()
    {
        $testingEnvironment = new Piwik_TestingEnvironment();

        if ($testingEnvironment->configFileLocal) {
            \Piwik\Config::$defaultLocalConfigPath = $testingEnvironment->configFileLocal;
        }

        if ($testingEnvironment->configFileCommon) {
            \Piwik\Config::$defaultCommonConfigPath = $testingEnvironment->configFileCommon;
        }

        if ($testingEnvironment->configFileGlobal) {
            \Piwik\Config::$defaultGlobalConfigPath = $testingEnvironment->configFileGlobal;
        }

        if ($testingEnvironment->queryParamOverride) {
            foreach ($testingEnvironment->queryParamOverride as $key => $value) {
                $_GET[$key] = $value;
            }
        }

        Piwik::addAction('Access.createAccessSingleton', function($access) use ($testingEnvironment) {
            if (!$testingEnvironment->testUseRegularAuth) {
                $access = new Piwik_MockAccess($access);
                \Piwik\Access::setSingletonInstance($access);
            }
        });
        Piwik::addAction('Config.createConfigSingleton', function($config) use ($testingEnvironment) {
            \Piwik\CacheFile::$invalidateOpCacheBeforeRead = true;

            $config->setTestEnvironment();

            $pluginsToLoad = \Piwik\Plugin\Manager::getInstance()->getPluginsToLoadDuringTests();
            $config->Plugins = array('Plugins' => $pluginsToLoad);

            $trackerPluginsToLoad = array(
                'Provider', 'Goals', 'PrivacyManager', 'UserCountry', 'DevicesDetection'
            );
            $config->Plugins_Tracker = array('Plugins_Tracker' => $trackerPluginsToLoad);
            $config->log['log_writers'] = array('file');

            $testingEnvironment->logVariables();
        });
        Piwik::addAction('Db.getDatabaseConfig', function (&$dbConfig) use ($testingEnvironment) {
            if ($testingEnvironment->dbName) {
                $dbConfig['dbname'] = $testingEnvironment->dbName;
            }
        });
        Piwik::addAction('Tracker.getDatabaseConfig', function (&$dbConfig) use ($testingEnvironment) {
            if ($testingEnvironment->dbName) {
                $dbConfig['dbname'] = $testingEnvironment->dbName;
            }
        });
        Piwik::addAction('Request.dispatch', function() {
            \Piwik\Plugins\CoreVisualizations\Visualizations\Cloud::$debugDisableShuffle = true;
            \Piwik\Visualization\Sparkline::$enableSparklineImages = false;
            \Piwik\Plugins\ExampleUI\API::$disableRandomness = true;
        });
        Piwik::addAction('AssetManager.getStylesheetFiles', function(&$stylesheets) {
            $stylesheets[] = 'tests/resources/screenshot-override/override.css';
        });
        Piwik::addAction('AssetManager.getJavaScriptFiles', function(&$jsFiles) {
            $jsFiles[] = 'tests/resources/screenshot-override/override.js';
        });
        Piwik::addAction('Test.Mail.send', function($mail) {
            $outputFile = PIWIK_INCLUDE_PATH . 'tmp/' . Common::getRequestVar('module') . '.' . Common::getRequestVar('action') . '.mail.json';

            $outputContent = str_replace("=\n", "", $mail->getBodyText($textOnly = true));
            $outputContent = str_replace("=0A", "\n", $outputContent);
            $outputContent = str_replace("=3D", "=", $outputContent);

            $outputContents = array(
                'from' => $mail->getFrom(),
                'to' => $mail->getRecipients(),
                'subject' => $mail->getSubject(),
                'contents' => $outputContent
            );
            
            file_put_contents($outputFile, Common::json_encode($outputContents));
        });
        Piwik::addAction('Updater.checkForUpdates', function () {
            \Piwik\Filesystem::deleteAllCacheOnUpdate();
        });
        Piwik::addAction('Request.dispatch.end', function (&$result, $parameters) {
            $enableZeitgeist = !empty($_REQUEST['zeitgeist']);
            if ($enableZeitgeist) {
                $replace = "action=getCss";
                $result = str_replace($replace, $replace . "&zeitgeist=1", $result);
            }
        });

        Piwik::postEvent("TestingEnvironment.addHooks", array($testingEnvironment), $pending = true); // for plugins that need to inject special testing logic
    }
}