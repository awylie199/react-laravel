<?php namespace React;

use Blade;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ReactServiceProvider extends ServiceProvider
{
    public function boot()
    {
        Blade::extend(function ($view) {
            $pattern = $this->createMatcher('react_component');

            return preg_replace($pattern, '<?php echo React::render$2; ?>', $view);
        });

        $prev = __DIR__ . '/../';

        $this->publishes([
          $prev . 'assets'            => public_path('vendor/react-laravel'),
          $prev . 'node_modules/react/dist' => public_path('vendor/react-laravel'),
          $prev . 'node_modules/react-dom/dist' => public_path('vendor/react-laravel'),
        ], 'assets');

        $this->publishes([
          $prev . 'config/config.php' => config_path('react.php'),
        ], 'config');
    }

    public function register()
    {
        $this->app->bind('React', function () {
            $lang = $this->getRequestLang();

            $langReactSourceName = 'reactSource';
            $langComponentsSourceName = "componentsSource-${lang}";

            if (App::environment('production')
                && Cache::has($langReactSourceName)
                    && Cache::has($langComponentsSourceName)) {
                $reactSource = Cache::get($langReactSourceName);
                $componentsSource = Cache::get($langComponentsSourceName);
            } else {
                $this->mergeConfigFrom(__DIR__ . '/../config/config.php', 'react');
                $components = config('react.components');
                $components = sprintf($components, $lang);

                $reactBaseSource = file_get_contents(config('react.source'));
                $reactDomSource = file_get_contents(config('react.dom-source'));
                $reactDomServerSource = file_get_contents(config('react.dom-server-source'));
                $componentsSource = file_get_contents($components);
                $reactSource = $reactBaseSource;
                $reactSource .= $reactDomSource;
                $reactSource .= $reactDomServerSource;

                if (App::environment('production')) {
                    Cache::forever($langReactSourceName, $reactSource);
                    Cache::forever($langComponentsSourceName, $componentsSource);
                }
            }

            return new React($reactSource, $componentsSource);
        });
    }

    /**
     * Get Language Code from Request
     * @return string               Language Request Code (Defaulting to 'en')
     */
    private function getRequestLang()
    {
        $lang = 'en';

        try {
            $acceptedLangs = array_keys(
                file_get_contents(base_path('resources/lang/languages.json'))
            );
            $requestLang = $_SERVER['HTTP_ACCEPT_LANGUAGE'];
            $requestLangParts = explode(',', $requestLang);

            if (count($requestLangParts) > 1 &&
                in_array($requestLangParts[1], $acceptedLangs)) {
                $lang = $requestLangParts[1];
            }
        } catch (\Exception $err) {
            if (!App::environment('production')) {
                Log::info('Unable to get lang in ReactServiceProvider');
            }
        } finally {
            return $lang;
        }
    }

    /**
     * Create a Regex for Matching the React Blade Component
     * @return string           String Regex for Matching React Blade Component
     */
    protected function createMatcher($function)
    {
        return '/(?<!\w)(\s*)@' . $function . '(\s*\([\s\S]*?\))/';
    }
}
