<?php

namespace hacklabr;

class Assets {
    private static $instances = [];
    protected $js_files;
    protected $css_files;

    protected function __construct() {
        $this->initialize();
    }

    public static function getInstance(){
        $cls = static::class;
        if (!isset(self::$instances[$cls])) {
            self::$instances[$cls] = new static();
        }

        return self::$instances[$cls];
    }

    /**
	 * Adds the action and filter hooks to integrate with WordPress.
	 */
	public function initialize() {
        $this->enqueue_styles();
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_javascripts' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_style' ] );
		add_action( 'after_setup_theme', [ $this, 'action_add_editor_styles' ] );
        add_filter( 'style_loader_tag', [ $this, 'add_rel_preload' ], 10, 4 );

		// add_action( 'wp_head', [ $this, 'action_preload_styles' ] );
	}

    /**
	 * Registers or enqueues stylesheets.
	 *
	 * Stylesheets that are global are enqueued. All other stylesheets are only registered, to be enqueued later.
	 */
	public function enqueue_styles() {
        add_action( 'wp_head', [ $this, 'enqueue_inline_styles' ], 99);
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_generic_styles' ] );
	}

    public function add_rel_preload($html, $handle, $href, $media) {
        if (!WP_DEBUG && (is_admin() || is_user_logged_in())) return $html;

        $html = "<link rel='stylesheet' onload=\"this.onload=null;this.media='all'\" id='$handle' href='$href' type='text/css' media='print' />";
        return $html;
    }

    public function should_preload_asset ($asset) {
        if ($asset['global']) {
            return true;
        }
        return is_callable( $asset['preload_callback'] ) && call_user_func( $asset['preload_callback'] );
    }

    public function enqueue_inline_styles() {
        $css_uri = get_stylesheet_directory() . '/dist/css/';

		$css_files = $this->get_css_files();
		foreach ( $css_files as $handle => $data ) {
            $src = $css_uri . $data['file'];
            $content = file_get_contents($src);

			if ( $data['inline'] && self::should_preload_asset( $data ) ) {
                echo "<style id='$handle-css'>" . $content . "</style>";
			}
		}
    }

    public function enqueue_generic_styles() {
        $css_uri = get_theme_file_uri( '/dist/css/' );
        $css_dir = get_theme_file_path( '/dist/css/' );

        $css_files = $this->get_css_files();
        foreach ( $css_files as $handle => $data ) {
            /**
             * Skip inline styles
             */
            if ($data['inline']) {
                continue;
            }

            $src = $css_uri . $data['file'];
            $version = (string) filemtime( $css_dir . $data['file'] );

            /**
             * Dependencies
             *
             * @see https://developer.wordpress.org/reference/functions/wp_enqueue_style/
             */
            $deps = [];
            if ( isset( $data['deps'] ) && ! empty( $data['deps'] ) ) {
                $deps = $data['deps'];
            }

            /*
            * Enqueue global stylesheets immediately and register the other ones for later use
            */
            if ( self::should_preload_asset( $data ) ) {
                wp_enqueue_style( $handle, $src, $deps, $version, $data['media'] );
            } else {
                wp_register_style( $handle, $src, $deps, $version, $data['media'] );
            }

            wp_style_add_data( $handle, 'precache', true );
        }
    }

    public function enqueue_javascripts() {
        $js_uri = get_theme_file_uri( '/dist/js/functionalities/' );
        $js_dir = get_theme_file_path( '/dist/js/functionalities/' );

        $js_files = $this->get_js_files();
        foreach ( $js_files as $handle => $data ) {
            $src = $js_uri . $data['file'];
            $version = (string) filemtime( $js_dir . $data['file'] );

            $deps = [];
            if ( isset( $data['deps'] ) && ! empty( $data['deps'] ) ) {
                $deps = $data['deps'];
            }

            if ( self::should_preload_asset( $data ) ) {
                wp_enqueue_script( $handle, $src, $deps, $version, true );
            }
        }
    }

	/**
	 * Register and enqueue a custom stylesheet in the WordPress admin.
	 */
	public function enqueue_admin_style() {
        $css_uri = get_theme_file_uri( '/dist/css/' );

        wp_enqueue_style('hacklabr-gutenberg', $css_uri . 'editor.css');
	}

	/**
	 * Preloads in-body stylesheets depending on what templates are being used.
	 *
	 * @link https://developer.mozilla.org/en-US/docs/Web/HTML/Preloading_content
	 */
	public function action_preload_styles() {
		$wp_styles = wp_styles();

		$css_files = $this->get_css_files();
		foreach ( $css_files as $handle => $data ) {

			// Skip if stylesheet not registered.
			if ( ! isset( $wp_styles->registered[ $handle ] ) ) {
				continue;
			}

			// Skip if no preload callback provided.
			if ( ! is_callable( $data['preload_callback'] ) ) {
				continue;
			}

			// Skip if preloading is not necessary for this request.
			if ( ! call_user_func( $data['preload_callback'] ) ) {
				continue;
			}

			$preload_uri = $wp_styles->registered[ $handle ]->src . '?ver=' . $wp_styles->registered[ $handle ]->ver;

			echo '<link rel="preload" id="' . esc_attr( $handle ) . '-preload" href="' . esc_url( $preload_uri ) . '" as="style">';
			echo "\n";
		}
	}

	/**
	 * Enqueues WordPress theme styles for the editor.
	 */
	public function action_add_editor_styles() {
		add_editor_style( 'assets/css/editor/editor-styles.min.css' );
	}

	/**
	 * Prints stylesheet link tags directly.
	 *
	 * This should be used for stylesheets that aren't global and thus should only be loaded if the HTML markup
	 * they are responsible for is actually present. Template parts should use this method when the related markup
	 * requires a specific stylesheet to be loaded. If preloading stylesheets is disabled, this method will not do
	 * anything.
	 *
	 * If the `<link>` tag for a given stylesheet has already been printed, it will be skipped.
	 *
	 * @param string ...$handles One or more stylesheet handles.
	 */
	public function print_styles( string ...$handles ) {
		$css_files = $this->get_css_files();
		$handles   = array_filter(
			$handles,
			function( $handle ) use ( $css_files ) {
				$is_valid = isset( $css_files[ $handle ] ) && ! $css_files[ $handle ]['global'];
				if ( ! $is_valid ) {
					/* translators: %s: stylesheet handle */
					_doing_it_wrong( __CLASS__ . '::print_styles()', esc_html( sprintf( __( 'Invalid theme stylesheet handle: %s', 'buddyx' ), $handle ) ), 'Buddyx 2.0.0' );
				}
				return $is_valid;
			}
		);

		if ( empty( $handles ) ) {
			return;
		}

		wp_print_styles( $handles );
	}

	/**
	 * Gets all CSS files.
	 *
	 * @return array Associative array of $handle => $data pairs.
	 */
	protected function get_css_files() : array {
		if ( is_array( $this->css_files ) ) {
			return $this->css_files;
		}

		$css_files = [
			'app' => [
				'file' => 'app.css',
				'global' => true,
				'inline' => false,
			],

			/*
            'page' => [
                'file' => 'p-page.css',
                'preload_callback' => function() {
					return !is_front_page() && is_page();
				},
            ],
			*/
		];

		/**
		 * Filters default CSS files.
		 *
		 * @param array $css_files Associative array of CSS files, as $handle => $data pairs.
		 * $data must be an array with keys 'file' (file path relative to 'assets/css'
		 * directory), and optionally 'global' (whether the file should immediately be
		 * enqueued instead of just being registered) and 'preload_callback' (callback)
		 * function determining whether the file should be preloaded for the current request).
		 */
		$css_files = apply_filters('css_files_before_output', $css_files);


		$this->css_files = [];
		foreach ( $css_files as $handle => $data ) {
			if ( empty( $data['file'] ) ) {
				continue;
			}

			$this->css_files[ $handle ] = array_merge(
				[
					'global'           => false,
					'preload_callback' => null,
					'media'            => 'all',
				],
				$data
			);
		}

		return $this->css_files;
	}


    /**
	 * Gets all JS files.
	 *
	 * @return array Associative array of $handle => $data pairs.
	 */
	protected function get_js_files() : array {
		if ( is_array( $this->js_files ) ) {
			return $this->js_files;
		}

		$js_files = [
            'app' => [
                'file' => 'app.js',
                'global' => true,
            ],

            'scroll-behavior'     => [
                'file' => 'anchor-behavior.js',
				'global' => true,
			],

			'search' => [
				'file'   => 'search.js',
				'global' => true,
			],

			'copy-url' => [
                'file' => 'copy-url.js',
                'global' => true,
			],

			'anchor-sidebar'     => [
				'file' => 'anchor-sidebar.js',
				'preload_callback' => function () {
					return is_page_template( 'page-anchor.php' );
				}
			],

            'tabs' => [
                'file' => 'tabs.js',
                'global' => true,
			],
 		];

		$js_files = apply_filters('js_files_before_output', $js_files);

		$this->js_files = [];
		foreach ( $js_files as $handle => $data ) {
			if ( empty( $data['file'] ) ) {
				continue;
			}

			$this->js_files[ $handle ] = array_merge(
				[
					'global'           => false,
					'preload_callback' => null,
				],
				$data
			);
		}

		return $this->js_files;
	}
}


$assets_manager = Assets::getInstance();
