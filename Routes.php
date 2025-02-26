<?php
/*
Plugin Name: Routes
Plugin URI: http://routes.upstatement.com
Description: The WordPress Timber Library allows you to write themes using the power Twig templates
Author: Jared Novack + Upstatement
Version: 0.3.1
Author URI: http://upstatement.com/

Usage:

Routes::map('/my-location', function(){
	//do stuff
	Routes::load('single.php', $data);
});
*/

class Routes {
	public $current_route;
	public $current_method;
	public $current_template;

	public $router;

	public static function getInstance() {
		static $instance;
		if (!$instance) {
			$instance = new self();
		}

		return $instance;
	}

	/**
	 * Routes constructor.
	 */
	function __construct(){
		add_action('init', array($this, 'match_current_request') );
		add_action('wp_loaded', array($this, 'match_current_request') );

		$this->router = new AltoRouter();
		$site_url = trailingslashit( get_bloginfo('url') );
		$base_path = parse_url($site_url, PHP_URL_PATH);

		$this->router->setBasePath($base_path);
	}

	static function match_current_request() {
		static $onlyonceplease;
		if ($onlyonceplease) {
			return;
		}
		$onlyonceplease = true;
		$route = self::getInstance()->router->match();
		self::getInstance()->current_route = $route;
		self::getInstance()->current_method = $_SERVER['REQUEST_METHOD'];

		if ($route && isset($route['target'])) {
			if ( isset($route['params']) ) {
				call_user_func($route['target'], $route['params']);
			} else {
				call_user_func($route['target']);
			}
		}
	}

	public static function path2url( $path ) {
		$path = realpath($path);
		if ($path && is_file($path) && substr($path, 0, strlen(ABSPATH)) === ABSPATH) {
			return site_url( substr($path, strlen(ABSPATH)) );
		}
		return false;
	}

	public static function generate( $route_or_route_name, $params = [], $method = null, $fullURL = false ) {
		$router = self::getInstance()->router;

		if (!is_array($params)) {
			$params = (array) $params;
		}
		/** @var AltoRouter $router */
		$url = $router->generate( self::convert_route($route_or_route_name), $params, $method);
		if ($fullURL) {
			$baseURL = get_bloginfo('url');
			$basePath = trailingslashit( "" . parse_url($baseURL, PHP_URL_PATH) );
			$baseURL = trailingslashit( "$baseURL" );
			$url = preg_replace('@^'. $basePath .'@', $baseURL, $url);
			$root_url = preg_replace('@'. $basePath .'$@', '/', $baseURL);
			$url = preg_replace('@^/@', $root_url, $url);
		}
		return $url;
	}

	// as we are redirecting the user, the method here is always GET
	public static function redirect( $route_or_route_name, $params = [] ) {
		$redirect = self::getInstance()->router->generate( self::convert_route($route_or_route_name), $params, 'GET');
		wp_redirect($redirect);
		exit;
	}


	/**
	 * @param string $route         A string to match (ex: 'myfoo')
	 * @param callable $callback    A function to run, examples:
	 *                              Routes::map('myfoo', 'my_callback_function');
	 *                              Routes::map('mybaq', array($my_class, 'method'));
	 *                              Routes::map('myqux', function() {
	 *                                  //stuff goes here
	 *                              });
	 */
	public static function map($route, $callback, $name = false, $methods = 'GET|POST|PUT|DELETE|PATCH') {
		$route = self::convert_route($route);
		self::getInstance()->router->map($methods, trailingslashit($route), $callback, $name);

		// this route is non-trailing-slashed and it will eventually be redirected by canonical_redirect to
		// a trailing-slashed route. This route should never be used actively, nor generated, so we DO NOT
		// allow passing the name.
		self::getInstance()->router->map($methods, untrailingslashit($route), $callback);
	}

	public static function any( $route, $callback, $name = false ) {
		self::map($route, $callback, $name, 'ANY');
	}

	public static function get( $route, $callback, $name = false ) {
		self::map($route, $callback, $name, 'GET');
	}

	public static function put( $route, $callback, $name = false ) {
		self::map($route, $callback, $name, 'PUT');
	}

	public static function post( $route, $callback, $name = false ) {
		self::map($route, $callback, $name, 'POST');
	}

	public static function delete( $route, $callback, $name = false ) {
		self::map($route, $callback, $name, 'DELETE');
	}

	public static function patch( $route, $callback, $name = false ) {
		self::map($route, $callback, $name, 'PATCH');
	}

	/**
	 * @return string 					A string in a format for AltoRouter
	 *                       			ex: [:my_param]
	 */
	public static function convert_route($route_string) {
		if (strpos($route_string, '[') > -1) {
			return $route_string;
		}
		$route_string = preg_replace('/(:)\w+/', '/[$0]', $route_string);
		$route_string = str_replace('[[', '[', $route_string);
		$route_string = str_replace(']]', ']', $route_string);
		$route_string = str_replace('[/:', '[:', $route_string);
		$route_string = str_replace('//[', '/[', $route_string);
		return $route_string;
	}

	/**
	 * @param string $template           A php file to load (ex: 'single.php')
	 * @param array|bool $tparams       An array of data to send to the php file. Inside the php file
	 *                                  this data can be accessed via:
	 *                                  global $params;
	 * @param int $status_code          A code for the status (ex: 200)
	 * @param WP_Query $query           Use a WP_Query object in the template file instead of
	 *                                  the default query
	 * @param int $priority		    The priority used by the "template_include" filter
	 * @return bool
	 */
	public static function load($template, $tparams = false, $query = false, $status_code = 200, $priority = 10) {
		$fullPath = is_readable($template);
		if (!$fullPath) {
			$template = locate_template($template);
		}
		if ($tparams){
			global $params;
			$params = $tparams;
			self::getInstance()->current_template = compact('template', 'params', 'query', 'status_code', 'priority');
		}
		if ($status_code) {
			add_filter('status_header', function($status_header, $header, $text, $protocol) use ($status_code) {
				$text = get_status_header_desc($status_code);
				$header_string = "$protocol $status_code $text";
				return $header_string;
			}, 10, 4 );
			if (404 != $status_code) {
				add_action('parse_query', function($query) {
					if ($query->is_main_query()){
						$query->is_404 = false;
						$query->is_attachment = false;
						$query->is_page = true;
					}
				},1);
				add_action('template_redirect', function(){
					global $wp_query;
					$wp_query->is_404 = false;
				},1);
			}
		}

		if ($query) {
			add_action('do_parse_request', function() use ($query) {
				global $wp;
				if ( is_callable($query) )
					$query = call_user_func($query);

				if ( is_array($query) )
					$wp->query_vars = $query;
				elseif ( !empty($query) )
					parse_str($query, $wp->query_vars);
				else
					return true; // Could not interpret query. Let WP try.

				return false;
			});
		}
		if ($template) {
			add_filter('template_include', function($t) use ($template) {
				return $template;
			}, $priority);
			return true;
		}
		return false;
	}
}

global $upstatement_routes;
$upstatement_routes = Routes::getInstance();

if (    file_exists($composer_autoload = __DIR__ . '/vendor/autoload.php')
		|| file_exists($composer_autoload = WP_CONTENT_DIR.'/vendor/autoload.php')){
  require_once($composer_autoload);
}

