<?php

namespace Mautic\CoreBundle\Helper;

use Symfony\Component\Finder\Finder;

class AssetGenerationHelper
{
    // Temporary array of libraries to load from node_modules before we switch
    // to Symfony Encore. This is the first step to load libraries from NPM.
    private const NODE_MODULES = [
        'mousetrap/mousetrap.js', // Needed for keyboard shortcuts
        'jquery/dist/jquery.js', // Needed for everything. It's the underlying framework.
        'history.js/scripts/bundled-uncompressed/html4+html5/jquery.history.js', // Needed for ajaxyfying the UI.
        'js-cookie/src/js.cookie.js', // Needed for cookies.
        'bootstrap/dist/js/bootstrap.js', // Needed for the UI components like bodal boxes.
        'jquery-form/src/jquery.form.js', // Needed for ajax forms with file attachments.
        'jquery-ui-touch-punch/jquery.ui.touch-punch.js', // Needed for touch devices.
        'moment/min/moment.min.js', // Needed for date/time formatting.
        // 'jquery-color/dist/jquery.color.js', // I can't find why is this needed. Added in https://github.com/mautic/mautic/commit/918000351e8c7657b01ef132e22c097942cf0e99. Uncoment this if we find the place. Delete this dependency after some time.
        'jquery.caret/dist/jquery.caret.js', // Needed for the text editor Twitter-like mentions (tokens).
        'codemirror/lib/codemirror.js', // Needed for the legacy code-mode editor.
        'codemirror/addon/hint/show-hint.js', // Needed for the legacy code-mode editor.
        'codemirror/mode/xml/xml.js', // Needed for the legacy code-mode editor.
        'codemirror/mode/javascript/javascript.js', // Needed for the legacy code-mode editor.
        'codemirror/mode/htmlmixed/htmlmixed.js', // Needed for the legacy code-mode editor.
        'codemirror/mode/css/css.js', // Needed for the legacy code-mode editor.
        // TODO: Add the rest of the libraries here.
    ];

    private BundleHelper $bundleHelper;
    private PathsHelper $pathsHelper;
    private string $version;

    public function __construct(CoreParametersHelper $coreParametersHelper, BundleHelper $bundleHelper, PathsHelper $pathsHelper, AppVersion $version)
    {
        $this->bundleHelper = $bundleHelper;
        $this->pathsHelper  = $pathsHelper;
        $this->version      = substr(hash('sha1', $coreParametersHelper->get('secret_key').$version->getVersion()), 0, 8);
    }

    /**
     * Generates and returns assets.
     *
     * @param bool $forceRegeneration
     *
     * @return array
     */
    public function getAssets($forceRegeneration = false)
    {
        static $assets = [];

        if (empty($assets)) {
            $loadAll    = true;
            $env        = ($forceRegeneration) ? 'prod' : MAUTIC_ENV;
            $rootPath   = $this->pathsHelper->getSystemPath('assets_root');
            $assetsPath = $this->pathsHelper->getSystemPath('assets');

            $assetsFullPath = "$rootPath/$assetsPath";
            if ('prod' == $env) {
                $loadAll = false; //by default, loading should not be required

                //check for libraries and app files and generate them if they don't exist if in prod environment
                $prodFiles = [
                    'css/libraries.css',
                    'css/app.css',
                    'js/libraries.js',
                    'js/app.js',
                ];

                foreach ($prodFiles as $file) {
                    if (!file_exists("$assetsFullPath/$file")) {
                        $loadAll = true; //it's missing so compile it
                        break;
                    }
                }
            }

            if ($loadAll || $forceRegeneration) {
                if ('prod' == $env) {
                    ini_set('max_execution_time', '300');

                    $inProgressFile = "$assetsFullPath/generation_in_progress.txt";

                    if (!$forceRegeneration) {
                        while (file_exists($inProgressFile)) {
                            //dummy loop to prevent conflicts if one process is actively regenerating assets
                        }
                    }
                    file_put_contents($inProgressFile, date('r'));
                }

                foreach (self::NODE_MODULES as $path) {
                    $relPath  = "node_modules/{$path}";
                    $fullPath = "{$this->pathsHelper->getRootPath()}/{$relPath}";
                    $ext      = pathinfo($relPath, PATHINFO_EXTENSION);
                    $details  = [
                        'fullPath' => $fullPath,
                        'relPath'  => $relPath,
                    ];

                    if ('prod' == $env) {
                        $assets[$ext]['libraries'][$relPath] = $details;
                    } else {
                        $assets[$ext][$relPath] = $details;
                    }
                }

                $modifiedLast = [];

                //get a list of all core asset files
                $bundles = $this->bundleHelper->getMauticBundles();

                $fileTypes = ['css', 'js'];
                foreach ($bundles as $bundle) {
                    foreach ($fileTypes as $ft) {
                        if (!isset($modifiedLast[$ft])) {
                            $modifiedLast[$ft] = [];
                        }
                        $dir = "{$bundle['directory']}/Assets/$ft";
                        if (file_exists($dir)) {
                            $modifiedLast[$ft] = array_merge($modifiedLast[$ft], $this->findAssets($dir, $ft, $env, $assets));
                        }
                    }
                }
                $modifiedLast = array_merge($modifiedLast, $this->findOverrides($env, $assets));

                //combine the files into their corresponding name and put in the root media folder
                if ('prod' == $env) {
                    $checkPaths = [
                        $assetsFullPath,
                        "$assetsFullPath/css",
                        "$assetsFullPath/js",
                    ];
                    array_walk($checkPaths, function ($path) {
                        if (!file_exists($path)) {
                            mkdir($path);
                        }
                    });

                    $useMinify = class_exists('\Minify');

                    foreach ($assets as $type => $groups) {
                        foreach ($groups as $group => $files) {
                            $assetFile = "$assetsFullPath/$type/$group.$type";

                            //only refresh if a change has occurred
                            $modified = ($forceRegeneration || !file_exists($assetFile)) ? true : filemtime($assetFile) < $modifiedLast[$type][$group];

                            if ($modified) {
                                if (file_exists($assetFile)) {
                                    //delete it
                                    unlink($assetFile);
                                }

                                if ('css' == $type) {
                                    $out = fopen($assetFile, 'w');

                                    foreach ($files as $relPath => $details) {
                                        $cssRel = '../../'.dirname($relPath).'/';
                                        if ($useMinify) {
                                            $content = \Minify::combine([$details['fullPath']], [
                                                'rewriteCssUris'  => false,
                                                'minifierOptions' => [
                                                    'text/css' => [
                                                        'currentDir'          => '',
                                                        'prependRelativePath' => $cssRel,
                                                    ],
                                                ],
                                            ]);
                                        } else {
                                            $content = file_get_contents($details['fullPath']);
                                            $search  = '#url\((?!\s*([\'"]?(((?:https?:)?//)|(?:data\:?:))))\s*([\'"])?#';
                                            $replace = "url($4{$cssRel}";
                                            $content = preg_replace($search, $replace, $content);
                                        }

                                        fwrite($out, $content);
                                    }

                                    fclose($out);
                                } else {
                                    array_walk($files, function (&$file) {
                                        $file = $file['fullPath'];
                                    });
                                    file_put_contents($assetFile, \Minify::combine($files));
                                }
                            }
                        }
                    }

                    unlink($inProgressFile);
                }
            }

            if ('prod' == $env) {
                //return prod generated assets
                $assets = [
                    'css' => [
                        "{$assetsPath}/css/libraries.css?v{$this->version}",
                        "{$assetsPath}/css/app.css?v{$this->version}",
                    ],
                    'js' => [
                        "{$assetsPath}/js/libraries.js?v{$this->version}",
                        "{$assetsPath}/js/app.js?v{$this->version}",
                    ],
                ];
            } else {
                foreach ($assets as &$typeAssets) {
                    $typeAssets = array_keys($typeAssets);
                }
            }
        }

        return $assets;
    }

    /**
     * Finds directory assets.
     *
     * @param string $dir
     * @param string $ext
     * @param string $env
     * @param array  $assets
     *
     * @return array
     */
    protected function findAssets($dir, $ext, $env, &$assets)
    {
        $rootPath    = str_replace('\\', '/', $this->pathsHelper->getSystemPath('assets_root').'/');
        $directories = new Finder();
        $directories->directories()->exclude('*less')->depth('0')->ignoreDotFiles(true)->in($dir);

        $modifiedLast = [];

        if (count($directories)) {
            foreach ($directories as $directory) {
                $group = $directory->getBasename();

                // Only auto load directories app or libraries
                if (!in_array($group, ['app', 'libraries'])) {
                    continue;
                }

                $files         = new Finder();
                $thisDirectory = str_replace('\\', '/', $directory->getRealPath());
                $files->files()->depth('0')->name('*.'.$ext)->in($thisDirectory);

                $sort = function (\SplFileInfo $a, \SplFileInfo $b) {
                    return strnatcmp($a->getRealpath(), $b->getRealpath());
                };
                $files->sort($sort);

                foreach ($files as $file) {
                    $fullPath = $file->getPathname();
                    $relPath  = str_replace($rootPath, '', $file->getPathname());
                    if (0 === strpos($relPath, '/')) {
                        $relPath = substr($relPath, 1);
                    }

                    $details = [
                        'fullPath' => $fullPath,
                        'relPath'  => $relPath,
                    ];

                    if ('prod' == $env) {
                        $lastModified = filemtime($fullPath);
                        if (!isset($modifiedLast[$group]) || $lastModified > $modifiedLast[$group]) {
                            $modifiedLast[$group] = $lastModified;
                        }
                        $assets[$ext][$group][$relPath] = $details;
                    } else {
                        $assets[$ext][$relPath] = $details;
                    }
                }
                unset($files);
            }
        }

        unset($directories);
        $files = new Finder();
        $files->files()->depth('0')->ignoreDotFiles(true)->name('*.'.$ext)->in($dir);

        $sort = function (\SplFileInfo $a, \SplFileInfo $b) {
            return strnatcmp($a->getRealpath(), $b->getRealpath());
        };
        $files->sort($sort);

        foreach ($files as $file) {
            $fullPath = str_replace('\\', '/', $file->getPathname());
            $relPath  = str_replace($rootPath, '', $fullPath);

            $details = [
                'fullPath' => $fullPath,
                'relPath'  => $relPath,
            ];

            if ('prod' == $env) {
                $lastModified = filemtime($fullPath);
                if (!isset($modifiedLast['app']) || $lastModified > $modifiedLast['app']) {
                    $modifiedLast['app'] = $lastModified;
                }
                $assets[$ext]['app'][$relPath] = $details;
            } else {
                $assets[$ext][$relPath] = $details;
            }
        }
        unset($files);

        return $modifiedLast;
    }

    /**
     * Find asset overrides in the template.
     *
     * @param $env
     * @param $assets
     *
     * @return array
     */
    protected function findOverrides($env, &$assets)
    {
        $rootPath      = $this->pathsHelper->getSystemPath('assets_root');
        $currentTheme  = $this->pathsHelper->getSystemPath('current_theme');
        $modifiedLast  = [];
        $types         = ['css', 'js'];
        $overrideFiles = [
            'libraries' => 'libraries_custom',
            'app'       => 'app_custom',
        ];

        foreach ($types as $ext) {
            foreach ($overrideFiles as $group => $of) {
                if (file_exists("$rootPath/$currentTheme/$ext/$of.$ext")) {
                    $fullPath = "$rootPath/$currentTheme/$ext/$of.$ext";
                    $relPath  = "$currentTheme/$ext/$of.$ext";

                    $details = [
                        'fullPath' => $fullPath,
                        'relPath'  => $relPath,
                    ];

                    if ('prod' == $env) {
                        $lastModified = filemtime($fullPath);
                        if (!isset($modifiedLast[$ext][$group]) || $lastModified > $modifiedLast[$ext][$group]) {
                            $modifiedLast[$ext][$group] = $lastModified;
                        }
                        $assets[$ext][$group][$relPath] = $details;
                    } else {
                        $assets[$ext][$relPath] = $details;
                    }
                }
            }
        }

        return $modifiedLast;
    }
}
