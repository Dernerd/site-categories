<?php
/*
Plugin Name: Blog Kategorien
Plugin URI: https://n3rds.work/wiki/piestingtal-source-wiki/blog-kategorien-plugin/
Description: Kategorisiere Webseiten in Deinem Multisite-Netzwerk ganz einfach mit Blog-Kategorien!
Author: WMS N@W
Version: 1.0.9.4
Author URI: https://n3rds.work
Text Domain: site-categories
Domain Path: languages

Copyright 2020 WMS N@W (https://n3rds.work)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License (Version 2 - GPLv2) as published by
the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/
///////////////////////////////////////////////////////////////////////////

require 'lib/plugin-update-checker/plugin-update-checker.php';
$MyUpdateChecker = Puc_v4_Factory::buildUpdateChecker(
	'https://n3rds.work//wp-update-server/?action=get_metadata&slug=site-categories', 
	__FILE__, 
	'site-categories' 
);

if (!defined('SITE_CATEGORIES_I18N_DOMAIN'))
	define('SITE_CATEGORIES_I18N_DOMAIN', 'site-categories');

if (!defined('SITE_CATEGORIES_TAXONOMY'))
	define('SITE_CATEGORIES_TAXONOMY', 'bcat');

require_once( dirname(__FILE__) . '/lib/widgets/class_site_categories_widget_categories.php');
require_once( dirname(__FILE__) . '/lib/widgets/class_site_categories_widget_category_sites.php');
require_once( dirname(__FILE__) . '/lib/widgets/class_site_categories_widget_cloud.php');

require_once( dirname(__FILE__) . '/lib/display_templates/display_list_category_sites.php');
require_once( dirname(__FILE__) . '/lib/display_templates/display_list_categories.php');
require_once( dirname(__FILE__) . '/lib/display_templates/display_grid_categories.php');
require_once( dirname(__FILE__) . '/lib/display_templates/display_accordion_categories.php');

class SiteCategories {
		
	private $_pagehooks = array();	// A list of our various nav items. Used when hooking into the page load actions.
	private $_messages	= array();	// Message set during the form processing steps for add, edit, udate, delete, restore actions
	private $_settings	= array();	// These are global dynamic settings NOT stores as part of the config options
	private $_signup_form_errors;
	
	private $_admin_header_error;	// Set during processing will contain processing errors to display back to the user
	private $bcat_signup_meta = array();	// Used to store the signup meta information related to Site Categories during the processing. 

	/**
	 * The PHP5 Class constructor. Used when an instance of this class is needed.
	 * Sets up the initial object environment and hooks into the various WordPress 
	 * actions and filters.
	 *
	 * @since 1.0.0
	 * @uses $this->_settings array of our settings
	 * @uses $this->_messages array of admin header message texts.
	 * @param none
	 * @return self
	 */
	function __construct() {
		
		// Add support for new PSOURCE Dashboard Notices
		global $psource_notices;
		$psource_notices[] = array( 'id'=> 679160,'name'=> 'Blog Kategorien', 'screens' => array( 'toplevel_page_bcat_settings', 'edit-bcat'));
		//include_once( dirname(__FILE__) . '/lib/dash-notices/psource-dash-notification.php' );
		
		$this->_settings['VERSION']					= '1.0.9.1';
		$this->_settings['MENU_URL']				= 'options-general.php?page=site_categories';
		//$this->_settings['PLUGIN_URL']				= plugins_url(basename( dirname(__FILE__) ));
		$this->_settings['PLUGIN_BASE_DIR']			= dirname(__FILE__);
		$this->_settings['admin_menu_label']		= __( "Blog Kategorien", SITE_CATEGORIES_I18N_DOMAIN ); 
		//echo "settings<pre>"; print_r($this->_settings); echo "</pre>";
		//die();
		
		$this->_settings['options_key']				= "psource-site-categories"; 
		
		$this->_admin_header_error					= "";		
		
		//add_action('admin_notices', array(&$this, 'admin_notices_proc') );

		/* Setup the tetdomain for i18n language handling see http://codex.wordpress.org/Function_Reference/load_plugin_textdomain */
		load_plugin_textdomain( SITE_CATEGORIES_I18N_DOMAIN, false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

		/* Standard activation hook for all WordPress plugins see http://codex.wordpress.org/Function_Reference/register_activation_hook */
		register_activation_hook( __FILE__,		array( &$this, 'plugin_activation_proc' ) );

		add_action( 'init',							array(&$this, 'init_proc') );		
		add_action( 'admin_enqueue_scripts',		array(&$this, 'admin_enqueue_scripts_proc'));
		add_action( 'wp_enqueue_scripts',			array(&$this, 'wp_enqueue_scripts_proc'), 99);
		
		
		add_action( 'admin_menu',					array(&$this, 'admin_menu_proc') );	
		add_action( 'widgets_init',					array(&$this, 'widgets_init_proc') );

		// Add/Modify the column for the Taxonomy terms list page 
		add_filter( "manage_edit-bcat_columns",		array(&$this, 'bcat_taxonomy_column_headers') );	
		add_filter( 'manage_bcat_custom_column',	array(&$this, 'bcat_taxonomy_column'), 10, 3 );
        add_filter( 'bcat_row_actions',				array(&$this, 'bcat_taxonomy_row_actions'), 10, 2 );

		add_filter( 'wpmu_blogs_columns',			array(&$this, 'bcat_sites_column_headers') );	
		add_action( 'manage_sites_custom_column',	array(&$this, 'bcat_sites_column_row'), 10, 2 );


		// Add/Edit Taxonomy term form fields. 
		add_action( 'bcat_edit_form_fields',		array(&$this, 'bcat_taxonomy_term_edit'), 99, 2 );		
		add_action( "edit_bcat",					array(&$this, 'bcat_taxonomy_term_save'), 99, 2 );
		
		// Adds our Site Categories to the Site signup form. 
		add_action( 'signup_blogform',				array($this, 'bcat_signup_blogform') );
		add_action( 'wpmu_new_blog',				array($this, 'wpmu_new_blog_proc'), 9999, 6 );		
		add_filter( 'wpmu_validate_blog_signup',	array($this, 'bcat_wpmu_validate_blog_signup'));
		add_filter( 'add_signup_meta',				array($this, 'bcat_add_signup_meta'));

		// Adds our Site Categories section to the BuddyPress register form
		add_action( 'bp_after_blog_details_fields', array($this, 'bcat_signup_blogform'), 99 );
		add_filter( 'bp_signup_usermeta', array($this, 'bcat_add_signup_meta'));
		
		// Output for the Title and Content of the Site Category listing page
		add_filter( 'the_title',					array($this, 'process_categories_title'), 99, 2 );
		add_filter( 'the_content',					array($this, 'process_categories_body'), 99 );
				
		// Rewrite rules logic
		add_filter( 'rewrite_rules_array',			array($this, 'insert_rewrite_rules') );
		add_filter( 'query_vars',					array($this, 'insert_query_vars') );
		
		add_action( 'delete_blog',					array($this, 'blog_change_status_count') );
		add_action( 'make_spam_blog',				array($this, 'blog_change_status_count') );
		add_action( 'make_ham_blog',				array($this, 'blog_change_status_count') );
		add_action( 'mature_blog',					array($this, 'blog_change_status_count') );
		add_action( 'unmature_blog',				array($this, 'blog_change_status_count') );		
		add_action( 'archive_blog',					array($this, 'blog_change_status_count') );
		add_action( 'unarchive_blog',				array($this, 'blog_change_status_count') );		
		add_action( 'activate_blog',				array($this, 'blog_change_status_count') );
		add_action( 'deactivate_blog',				array($this, 'blog_change_status_count') );		
	}	
	
	/**
	 * The old-style PHP Class constructor. Used when an instance of this class 
	 * is needed. If used (PHP4) this function calls the PHP5 version of the constructor.
	 *
	 * @since 1.0.0
	 * @param none
	 * @return self
	 */
	function SiteCategories() {
		__construct();
	}

	function init_proc() {
		$this->register_taxonomy_proc();
	}

	/**
	 * Setup scripts and stylsheets
	 *
	 * @since 1.0.0
	 * @param none
	 * @return none
	 */
	function wp_enqueue_scripts_proc() {
		if (isset($this->opts['categories']['show_style']) &&  ($this->opts['categories']['show_style'] == "accordion")) {
			wp_enqueue_script('jquery');
			wp_enqueue_script('jquery-ui-accordion');

			wp_register_script('site-categories', plugins_url('/js/jquery.site-categories.js', __FILE__), 
				array('jquery', 'jquery-ui-accordion'), $this->_settings['VERSION']  );
			wp_enqueue_script('site-categories');
		}
	
		wp_register_style( 'site-categories-styles', plugins_url('css/site-categories-styles.css', __FILE__) );
		wp_enqueue_style( 'site-categories-styles' );			
	}

	function admin_enqueue_scripts_proc()
	{
		if (!is_multisite())
			return;
		
		global $wp_version; 

		wp_register_style( 'site-categories-admin-styles', plugins_url('css/site-categories-admin-styles.css', __FILE__) );
		wp_enqueue_style( 'site-categories-admin-styles' );

		$site_categories_data = array();
		$site_categories_data['wp_version'] = $wp_version;
		if ( (is_main_site()) && (is_super_admin()) ) {
			
			if ((isset($_GET['taxonomy'])) && ($_GET['taxonomy'] == "bcat")
			 && (isset($_GET['tag_ID']))) {
				//echo "wp_version[". $wp_version ."]<br />";
				if ( version_compare( $wp_version, '3.8', '>=' )) {
					if (function_exists('wp_enqueue_media')) {
						wp_enqueue_media();
						$site_categories_data['image_view'] = 'new_media';
						$site_categories_data['image_view_title_text'] = __('W??hle ein Bild f??r die Blog-Kategorie', SITE_CATEGORIES_I18N_DOMAIN);
						$site_categories_data['image_view_button_text'] = __('Verwende das Bild', SITE_CATEGORIES_I18N_DOMAIN);
					} else {
						add_thickbox();
						$site_categories_data['image_view'] = 'thickbox';
					}
				} else {
					add_thickbox();
					$site_categories_data['image_view'] = 'thickbox';
				}

				wp_register_script('site-categories-admin', plugins_url('/js/jquery.site-categories-admin.js', __FILE__), 
					array('jquery'), $this->_settings['VERSION']  );
				wp_enqueue_script('site-categories-admin');
				
				
			} else if ((isset($_GET['page'])) && ($_GET['page'] == "bcat_settings")) {
				if (version_compare($wp_version, '3.8') >= 0) {	
					if (function_exists('wp_enqueue_media')) {
						wp_enqueue_media();
						$site_categories_data['image_view'] = 'new_media';
						$site_categories_data['image_view_title_text'] = __('W??hle ein Bild f??r die Blog-Kategorie', SITE_CATEGORIES_I18N_DOMAIN);
						$site_categories_data['image_view_button_text'] = __('Verwende das Bild', SITE_CATEGORIES_I18N_DOMAIN);
					} else {
						add_thickbox();
						$site_categories_data['image_view'] = 'thickbox';
					}						
				} else {
					add_thickbox();
					$site_categories_data['image_view'] = 'thickbox';						
				}

				wp_register_script('site-categories-admin', plugins_url('/js/jquery.site-categories-admin.js', __FILE__), 
					array('jquery'), $this->_settings['VERSION']  );
				wp_enqueue_script('site-categories-admin');
			}
			wp_localize_script('site-categories-admin', 'site_categories_data', $site_categories_data);
		}
	}

	/**
	 * Initialize our widgets
	 *
	 * @since 1.0.0
	 * @param none
	 * @return none
	 */	
	function widgets_init_proc() {
		if (!is_multisite()) 
			return;
		
		$this->load_config();
		
		register_widget('Bcat_WidgetCategories');
		register_widget('Bcat_WidgetCategorySites');		
		register_widget('Bcat_WidgetCloud');		
	}
		
	function bcat_taxonomy_column_headers($columns) {
		if (isset($columns['posts'])) {
			unset($columns['posts']);
		}
		
		$columns_tmp = array();
		if (isset($columns['cb'])) {
			$columns_tmp['cb'] = $columns['cb'];
			unset($columns['cb']);
		}

		$columns_tmp['icon'] = __('Icon', SITE_CATEGORIES_I18N_DOMAIN);
		foreach($columns as $col_key => $col_label) {
			$columns_tmp[$col_key] = $col_label;
		}
		$columns_tmp['sites'] = __('Seiten', SITE_CATEGORIES_I18N_DOMAIN); 

		return $columns_tmp;
	}
    
    function bcat_taxonomy_row_actions( $actions, $tag ){

		if ((isset($this->opts['landing_page_slug'])) && (strlen($this->opts['landing_page_slug']))) {

			if ((isset($this->opts['landing_page_rewrite'])) && ($this->opts['landing_page_rewrite'] == true) && ($this->opts['landing_page_use_rewrite'] == "yes")) {
				$bcat_url = trailingslashit($this->opts['landing_page_slug']) . $tag->slug;
			} else {
				$landing_page_slug = $this->opts['landing_page_slug'];
				$bcat_url = $landing_page_slug . (strpos($landing_page_slug, '?') > 0  ? '&amp;' : '?') . 'category=' . $tag->slug;
			}

			if (strlen($bcat_url)) {

				$label = sprintf( __( 'Zur Seite ', SITE_CATEGORIES_I18N_DOMAIN ), $tag->name );				
				$actions['view'] = '<a href="' . $bcat_url . '" aria-label="' . $label  . '">' . __( 'Zur Seite', SITE_CATEGORIES_I18N_DOMAIN ) . '</a>';
				
			}					

		}

		return $actions;
	}

	/**
	 * On the Primary site under the Site Categories section will be a Taxonomy admin panel. This function adds a column
	 * to the standard WordPress taxonomy table. 
	 *
	 * @since 1.0.0
	 * @param none
	 * @return none
	 */
	function bcat_taxonomy_column($nothing, $column_name, $term_id) {
		switch($column_name) {
			
			case 'sites':
				$bcat_term = get_term($term_id, SITE_CATEGORIES_TAXONOMY);
				if ( !is_wp_error($bcat_term)) {
					
					if ($bcat_term->count == 0) {
						echo $bcat_term->count;
					} else {
						if ((isset($this->opts['landing_page_slug'])) && (strlen($this->opts['landing_page_slug']))) {

							if ((isset($this->opts['landing_page_rewrite'])) && ($this->opts['landing_page_rewrite'] == true) && ($this->opts['landing_page_use_rewrite'] == "yes")) {
								$bcat_url = trailingslashit($this->opts['landing_page_slug']) . $bcat_term->slug;
							} else {
								$landing_page_slug = $this->opts['landing_page_slug'];
								$bcat_url = $landing_page_slug . (strpos($landing_page_slug, '?') > 0  ? '&amp;' : '?') . 'category=' . $bcat_term->slug;
							}

							if (strlen($bcat_url)) {
								?><a target="_blank" href="<?php echo $bcat_url; ?>"><?php echo $bcat_term->count; ?></a><?php
							} else {
								echo $bcat_term->count;
							}						

						} else {
							echo $bcat_term->count;
						}
					}
				}
				break;

			case 'icon':
				$bcat_image_src = '';

				$this->load_config();
				if (isset($this->opts['icons_category'][$term_id])) {
					$bcat_image_id = $this->opts['icons_category'][$term_id];
					if ($bcat_image_id)
					{
						$image_src	= wp_get_attachment_image_src($bcat_image_id, 'thumbnail', true);
						//echo "image_src<pre>"; print_r($image_src); echo "</pre>";
						
						if ($image_src) {
							$bcat_image_src = $image_src[0];
						}
					}
				}
				
				if (!strlen($bcat_image_src)) {
					$bcat_image_src = $this->get_default_category_icon_url();
				}
				if (is_ssl()) {
					$bcat_image_src = str_replace('http://', 'https://', $bcat_image_src);
				}
				?><img src="<?php echo $bcat_image_src; ?>" alt="" width="50" /><?php

				break;
			
			default:
				break;
		}
	}
	
	function bcat_sites_column_headers($columns) {
		if (!isset($columns['site-categories']))
			$columns['site-categories'] = __('Seiten-Kategorien', SITE_CATEGORIES_I18N_DOMAIN); 
		return $columns;
	}
	function bcat_sites_column_row($column_name, $blog_id) {
		switch($column_name) {
			case 'site-categories':
				$terms = wp_get_object_terms( $blog_id, SITE_CATEGORIES_TAXONOMY);
				if ((!$terms) || (!is_array($terms))) 
					$terms = array();
					
				$this->load_config();
				//echo "this->opts<pre>"; print_r($this->opts); echo "</pre>";
				$column_output = '';	
				foreach($terms as $bcat_term) {
					
					if ((isset($this->opts['landing_page_slug'])) && (strlen($this->opts['landing_page_slug']))) {

						if ((isset($this->opts['landing_page_rewrite'])) && ($this->opts['landing_page_rewrite'] == true) && ($this->opts['landing_page_use_rewrite'] == "yes")) {
							$bcat_url = trailingslashit($this->opts['landing_page_slug']) . $bcat_term->slug;
						} else {
							$landing_page_slug = $this->opts['landing_page_slug'];
							$bcat_url = $landing_page_slug . (strpos($landing_page_slug, '?') > 0  ? '&amp;' : '?') . 'category=' . $bcat_term->slug;
						}

						if (strlen($bcat_url)) {
							if (strlen($column_output)) $column_output .= ", ";
							$column_output .= '<a target="_blank" href="'. $bcat_url .'">'. $bcat_term->name .'</a>';
						} else {
							if (strlen($column_output)) $column_output .= ", ";
							$column_output .= $bcat_term->count;
						}						

					} else {
						if (strlen($column_output)) $column_output .= ", ";
						$column_output .= $bcat_term->name;
					}
				}
				if (strlen($column_output))
					echo $column_output;
				
				
				break;
			
			default:
				break;
		}
	}
	
	/**
	 * Gets the URL for the default icon shipped with the plugin
	 *
	 * @since 1.0.0
	 * @param none
	 * @return none
	 */
	
	function get_default_category_icon_url() {
		return plugins_url('/img/default.jpg', __FILE__);
	}

	/**
	 * Gets the path for the default icon shipped with the plugin 
	 *
	 * @since 1.0.0
	 * @param none
	 * @return none
	 */

	function get_default_category_icon_path() {
		return WP_PLUGIN_DIR .'/'. basename(dirname(__FILE__)) .'/img/default.jpg';
	}
	

	/**
	 * Reads the taxonomy and returns the URL to the taxonomy term icon.
	 *
	 * @since 1.0.0
	 * @param none
	 * @return none
	 */
	function get_category_term_icon_src($term_id, $size) {	

		if ((isset($this->opts['icons_category'][$term_id])) && (intval($this->opts['icons_category'][$term_id]))) {
			$icon_image_id = $this->opts['icons_category'][$term_id];
			$icon_image_src = wp_get_attachment_image_src($icon_image_id, array($size, $size));
			if ($icon_image_src) {
				if (is_ssl()) {
					$icon_image_src[0] = str_replace('http://', 'https://', $icon_image_src[0]);
				}
				return $icon_image_src[0];
			}

		} else if ((isset($this->opts['categories']['default_icon_id'])) && (intval($this->opts['categories']['default_icon_id']))) {
			$default_icon_id = $this->opts['categories']['default_icon_id'];
			$icon_image_src = wp_get_attachment_image_src($default_icon_id, array($size, $size), true);
			if (( !is_wp_error($icon_image_src)) && ($icon_image_src !== false)) {
				if (($icon_image_src) && (isset($icon_image_src[0])) && (strlen($icon_image_src[0]))) {
					if (is_ssl()) {
						$icon_image_src[0] = str_replace('http://', 'https://', $icon_image_src[0]);
					}
					return $icon_image_src[0];
				} 
			} 
			
		} else {
			$icon_image_path = $this->get_default_category_icon_path();
			$icon_image_src = image_make_intermediate_size($icon_image_path, $size, $size, true);
			if (( !is_wp_error($icon_image_src)) && ($icon_image_src !== false)) {
				if (($icon_image_src) && (isset($icon_image_src['file']))) {
					$image_src = dirname($this->get_default_category_icon_url()) ."/". $icon_image_src['file'];
					if (is_ssl()) {
						$image_src = str_replace('http://', 'https://', $image_src);
					}
					return $image_src;
				} 
			} 
		}

		if ((isset($icon_image_path)) && (strlen($icon_image_path))) {
			$image_src = dirname($this->get_default_category_icon_url()) ."/". basename($icon_image_path);
			if (is_ssl()) {
				$image_src = str_replace('http://', 'https://', $image_src);
			}
			return $image_src;
		}
	}
	
	
	/**
	 * Called when the Site Categories term is edited
	 *
	 * @since 1.0.0
	 * @param none
	 * @return none
	 */
	function bcat_taxonomy_term_edit($tag, $taxonomy) {

		// Should not happen. But just in case.
		if ($tag->taxonomy != "bcat")	return;
		
		$this->load_config();

		if (isset($this->opts['icons_category'][$tag->term_id])) {
			$bcat_image_id = $this->opts['icons_category'][$tag->term_id];
		} else {
			$bcat_image_id = 0;
		}
		?>
<?php /* ?>			
		<tr>
			<th scope="row" valign="top"><label for="bcat_category_type"><?php _ex('Category Type', 'Category Type', SITE_CATEGORIES_I18N_DOMAIN); ?></label></th>
			<td>
				<ul>
					<li><input type="radio" name="bcat_category_type" id="bcat_category_type_regular" value="" /> <label 
						for="bcat_category_type_regular"><?php _e('Regular', SITE_CATEGORIES_I18N_DOMAIN); ?></label></li>
					<li><input type="radio" name="bcat_category_type" id="bcat_category_type_network_admin" value="" /> <label 
						for="bcat_category_type_network_admin"><?php _e('Network Admin Assigned', SITE_CATEGORIES_I18N_DOMAIN); ?></label></li>
			</td>
		</tr>	
<?php */ ?>

		<tr>
			<th scope="row" valign="top"><label for="upload_image"><?php _ex('Bild', 'Kategorie Bild', SITE_CATEGORIES_I18N_DOMAIN); ?></label></th>
			<td>
				<p class="description"><?php _e('The image used for the category icon will be displayed square.', SITE_CATEGORIES_I18N_DOMAIN) ?></p>
				<input type="hidden" id="bcat_image_id" value="<?php echo $bcat_image_id; ?>" name="bcat_image_id" />
				<input id="bcat_image_upload" class="button-secondary" type="button" value="<?php _e('Bild ausw??hlen', SITE_CATEGORIES_I18N_DOMAIN); ?>" <?php
					if ($bcat_image_id) { echo ' style="display: none;" '; }; ?> />
				<input id="bcat_image_remove" class="button-secondary" type="button" value="<?php _e('Entferne Bild', SITE_CATEGORIES_I18N_DOMAIN); ?>" <?php
					if (!$bcat_image_id) { echo ' style="display: none;" '; }; ?> />
				<br />
				<?php
					$bcat_image_default_src = $this->get_default_category_icon_url();
					if ($bcat_image_id)
					{
						$image_src	= wp_get_attachment_image_src($bcat_image_id, array(100, 100));
						if (!$image_src) {
							$image_src[0] = "#";							
						}
					} else {
						$image_src[0] = $bcat_image_default_src;
					}
					if (is_ssl()) {
						$image_src[0] = str_replace('http://', 'https://', $image_src[0]);
					}
					
					?>
					<img id="bcat_image_src" src="<?php echo $image_src[0]; ?>" alt="" style="margin-top: 10px; max-width: 300px; max-height: 300px" 
						rel="<?php echo $bcat_image_default_src; ?>"/>
					<?php
				?></p>
			</td>
		</tr>
		<?php
	}
	
	/**
	 * Called when the Site Categories taxonomy term is saved. 
	 *
	 * @since 1.0.0
	 * @param none
	 * @return none
	 */
	function bcat_taxonomy_term_save($term_id, $tt_id) {

		if (isset($_POST['bcat_image_id'])) {

			$bcat_image_id = intval($_POST['bcat_image_id']);

			$this->load_config();

			if (!isset($this->opts['icons_category']))
				$this->opts['icons_category'] = array();

			$this->opts['icons_category'][$term_id] = $bcat_image_id;
			
			$this->save_config();
		}
		
		//echo "term_id=[". $term_id ."]<br />";
		//echo "tt_id=[". $tt_id ."]<br />";
		$this->bcat_taxonomy_terms_count(array($tt_id), get_taxonomy(SITE_CATEGORIES_TAXONOMY));
	}
	
	/**
	 * Reads the Site Taxonomy and returns sites associated with a term
	 *
	 * @since 1.0.0
	 * @param none
	 * @return none
	 */	
	function get_taxonomy_sites($term_id, $args = array()) {
		
		global $wpdb;

		$defaults = array(
			'include_children'	=>	false,
			'orderby'			=>	$this->opts['sites']['orderby'],
			'order'				=>	$this->opts['sites']['order'],
			'taxonomy'			=>	SITE_CATEGORIES_TAXONOMY,
			'fields'			=>	'ids'
		);
			
		$args = wp_parse_args( $args, $defaults );
		
		//echo "term_id[". $term_id ."] args<pre>"; print_r($args); echo "</pre>";
		//die();
		
		$terms = array();
		if ((isset($args['context'])) && ($args['context'] == "widget")) {
		
			// If we are to include children we query via get_terms for child_of term_id. Those returned category terms (if any)
			// are then combined with the other term ids passed via the 'include' array.
			if ($args['include_children'] == true) {

				if ( (isset($args['include-and'])) && (is_array($args['include-and'])) && (count($args['include-and']))) {
					if (!empty($term_id)) 
						$terms[] = $term_id;
				} else {
					
					$args['taxonomy']	= SITE_CATEGORIES_TAXONOMY;
					if ((!empty($term_id)) && ($term_id != -1))
						$args['child_of']	= $term_id;

					// Copy this to a temp array so we can make changes without effectin the main $args array.
					$get_terms_args = $args;

					// Need to remove the 'include' parameter because WP will limit it to be only terms which are child_of selected term
					//if (isset($get_terms_args['include'])) {
					//	unset($get_terms_args['include']);
					//}
		
					//echo "get_terms_args<pre>"; print_r($get_terms_args); echo "</pre>";

					// Children terms only.
					$terms = get_terms( SITE_CATEGORIES_TAXONOMY, $get_terms_args );
					// Include top level too.
					$terms[] = $term_id;
				}
			} else {
				if ((!empty($term_id)) && (!isset($args['include-and']))) {
					if (is_admin())
						$args['parent'] = 0;
					else
						$args['parent'] = '';
				
					//echo "args<pre>"; print_r($args); echo "</pre>";
					//$terms = get_terms( SITE_CATEGORIES_TAXONOMY, $args );
					$terms[] = $term_id;
				}
			}
		} else {
		
			if ((!empty($term_id)) && (intval($term_id) > 0)) {
				$terms[] = $term_id;
			}
		}
		//echo "terms<pre>"; print_r($terms); echo "</pre>";
		//die();
				
		if ((isset($args['include-and'])) && (is_array($args['include-and'])) && (count($args['include-and']))) {
			if ((!empty($term_id)) && (intval($term_id) > 0)) {
				$terms[] = $term_id;
			}	
			$terms = array_unique(array_merge($terms, $args['include-and']));
			//echo "terms<pre>"; print_r($terms); echo "</pre>";

			$sites_by_term = array();
			foreach($terms as $term_id) {
				$sites = get_objects_in_term( $term_id, SITE_CATEGORIES_TAXONOMY);
				//echo "term_id[". $term_id ."] sites<pre>"; print_r($sites); echo "</pre>";
				if (($sites) && (is_array($sites)) && (count($sites))) {
					$sites_by_term[$term_id] = $sites;
				} else {
					$sites_by_term[$term_id] = array();
				}
			}
			//echo "sites_by_term<pre>"; print_r($sites_by_term); echo "</pre>";
			//die();
			
			$term_sites = array_shift($sites_by_term);
			foreach($sites_by_term as $sites){
				if (($sites) && (is_array($sites)) && (count($sites)))
					 $term_sites = array_intersect($term_sites, $sites);
			}			
			//echo "term_sites<pre>"; print_r($term_sites); echo "</pre>";
		} else if (($terms) && (count($terms))) {
			
			$term_sites = get_objects_in_term( $terms, SITE_CATEGORIES_TAXONOMY);
		}
					
		//Paul Kevin
		//Incase we are dealing with the default category, we still need to show the child categories
		if ($term_id == $this->opts['sites']['category_default']) {
			$unassigned_sites = $this->get_unassigned_sites();
			if(empty($term_sites)){
				//If the categories are empty and we are on the default, we show all unassigned sites
				$term_sites = $unassigned_sites;
			}else{
				//We merge the unassigned sites to the default category to match the count
				$term_sites = array_merge($term_sites, $unassigned_sites);
			}
		}

		//echo "term_sites<pre>"; print_r($term_sites); echo "</pre>";
		
		if ((isset($term_sites)) && (count($term_sites))) {
			$sites = array();
			
			foreach($term_sites as $site_id) {
				$blog = get_blog_details($site_id);
				//echo "blog<pre>"; print_r($blog); echo "</pre>";
				
				if (($blog) && ($blog->public != 0) && ($blog->archived == 0) && ($blog->spam == 0) && ($blog->deleted == 0) && ($blog->mature == 0)) {
					
					if ((isset($args['blog_filter'])) && (isset($args['blog_ids'])) && (count($args['blog_ids']))) {
						if ($args['blog_filter'] == "exclude") {
							if (array_search($blog->blog_id, $args['blog_ids']) !== false) {
								continue;
							}
						}
						if ($args['blog_filter'] == "include") {
							if (array_search($blog->blog_id, $args['blog_ids']) === false) {
								continue;
							}
							
						}
					}
					
					switch($args['orderby']) {
						case 'id':
							$sites[$blog->blog_id] = $blog;
							break;
													
						case 'registered':
							$sites[$blog->registered] = $blog;
							break;

						case 'last_updated':
							$sites[$blog->last_updated] = $blog;
							break;

						case 'name':
						default:
							$sites[$blog->blogname] = $blog;
							break;

					}
					if ($args['order'] == "ASC") {
						krsort($sites);
						ksort($sites);
						
					} else if ($args['order'] == "DESC") {
						ksort($sites);
						krsort($sites);
					}
				}
			}
			//echo "sites<pre>"; print_r($sites); echo "</pre>";
			
			return $sites;
		} else {
			return array();
		}
	}
	
		
	/**
	 * Called when when our plugin is activated. Sets up the initial settings 
	 * and creates the initial Snapshot instance. 
	 *
	 * @since 1.0.0
	 * @uses none
	 * @see $this->__construct() when the action is setup to reference this function
	 *
	 * @param none
	 * @return none
	 */
	function plugin_activation_proc() {
		
	}

	/**
	 * Loads the config data from the primary site options
	 *
	 * @since 1.0.0
	 * @param none
	 * @return none
	 */	
	function load_config() {
		global $wpdb, $blog_id, $current_site; 
		

		$defaults = array(
			'landing_page_id'			=>	0,
			'landing_page_slug'			=>	'',
			'landing_page_use_rewrite'	=>	'yes',
			'sites'										=>	array(
				'header_prefix'							=>	__('Kategorie', SITE_CATEGORIES_TAXONOMY),
				'return_link_label'						=>	__('Zur??ck', SITE_CATEGORIES_TAXONOMY),
				'return_link'							=>	1,
				'open_blank'							=>	0,
				'per_page'								=>	5,
				'icon_show'								=>	1,
				'icon_size'								=>	32,
				'orderby'								=>	'name',
				'order'									=>	'ASC',
				'show_style'							=>	'ul',
				'show_description'						=>	0,
				'category_limit'						=>	10,
				'signup_category_minimum'				=>	1,
				'category_default'						=>	0,
				'category_excludes'						=>	'',
				'signup_category_parent_selectable'		=>	1,
				'signup_show'							=>	1,
				'signup_category_required'				=>	1,
				'signup_category_label'					=>	__('Seiten-Kategorien', SITE_CATEGORIES_TAXONOMY),
				'signup_description_required'			=>	1,
				'signup_description_label'				=>	__('Seitenbeschreibung', SITE_CATEGORIES_TAXONOMY)
			),
			
			'categories'								=>	array(
				'per_page'								=>	5,
				'hide_empty'							=>	0,
				'hide_empty_children'					=>	0,
				'show_description'						=>	0,
				'show_description_children'				=>	0,
				'show_counts'							=>	0,
				'show_counts_children'					=>	0,
				'icon_show'								=>	0,
				'icon_show_children'					=>	0,
				'icon_size'								=>	32,
				'icon_size_children'					=>	32,
				'show_style'							=>	'ul',
				'show_style_children'					=>	'ul',
				'grid_cols'								=>	3,
				'grid_rows'								=>	3,
				'orderby'								=>	'name',
				'order'									=>	'ASC',
				'orderby_children'						=>	'name',
				'order_children'						=>	'ASC',
			)
		);


		//$this->_settings['options_key']				= "site-categories-". $this->_settings['VERSION']; 

		$this->opts = get_blog_option( $current_site->blog_id, $this->_settings['options_key'], false);
		if (!$this->opts) {
			
			$legacy_versions = array('1.0.4', '1.0.3', '1.0.2', '1.0.1', '1.0.0');
			
			foreach($legacy_versions as $legacy_version) {
				$options_key = "site-categories-". $legacy_version;
				$this->opts = get_blog_option( $wpdb->blogid, $options_key );

				if (!empty($this->opts)) {
					$this->opts['version'] = $legacy_version;
					break;
				}
			}
			
			if (empty($this->opts)) {
				$this->opts = $defaults;
			}
			
			// Now that we have loaded the legacy or default options save it. 
			$this->save_config();
				
		} else {
			
			if (!isset($this->opts['sites']))
				$this->opts['sites'] = $defaults['sites'];
			else
				$this->opts['sites'] = wp_parse_args( (array) $this->opts['sites'], $defaults['sites'] );

			if (!isset($this->opts['categories']))
				$this->opts['categories'] = $defaults['categories'];
			else
				$this->opts['categories'] = wp_parse_args( (array) $this->opts['categories'], $defaults['categories'] );
				
			$this->opts = wp_parse_args( (array) $this->opts, $defaults );			
			
			//echo "opts<pre>"; print_r($this->opts); echo "</pre>";
		}
	}
	
	/**
	 * Save our config information to the primary site options
	 *
	 * @since 1.0.0
	 * @param none
	 * @return none
	 */	
	function save_config() {
		global $current_site;
		
		$this->opts['version'] = $this->_settings['VERSION'];
		
		update_blog_option( $current_site->blog_id, $this->_settings['options_key'], $this->opts);		
	}
	
	/**
	 * Setup the rewrite rules for our Taxonomy terms. 
	 *
	 * @since 1.0.0
	 * @param none
	 * @return none
	 */
	
	function insert_rewrite_rules ($old) {
		global $wp_rewrite;
		
		if (!is_multisite()) return $old;
		
		$this->load_config();

		if ( (isset($wp_rewrite)) && ($wp_rewrite->using_permalinks()) && ($this->opts['landing_page_use_rewrite'] == "yes")) {
		
			$site_url = get_site_url();
			$landing_page_slug = str_replace(trailingslashit($site_url), '', $this->opts['landing_page_slug']);
			if ($landing_page_slug) {
				$landing_page_slug = untrailingslashit($landing_page_slug);
		
				$new = array(
					'(' . $landing_page_slug . ')/([^/]*)/?$' => 'index.php?pagename=$matches[1]&category_name=$matches[2]',
					'(' . $landing_page_slug . ')/([^/]*)/(\d+)/?$' => 'index.php?pagename=$matches[1]&category_name=$matches[2]&start_at=$matches[3]',
					);
				//echo "new<pre>"; print_r($new); echo "</pre>";
				return $new + $old;
			}
		}	
		return $old;
	}

	/**
	 * 
	 *
	 * @since 1.0.0
	 * @param none
	 * @return none
	 */
	function insert_query_vars ($vars) {
		global $wp_rewrite;
		
		if (!is_multisite()) return $vars;
				
		$this->load_config();
		if ( (isset($wp_rewrite)) && ($wp_rewrite->using_permalinks()) && ($this->opts['landing_page_use_rewrite'] == "yes")) {
			//echo "wp_rewrite<pre>"; print_r($wp_rewrite); echo "</pre>";
			
			$vars[] = 'category_name';
			$vars[] = 'start_at';
			
			//echo "vars<pre>"; print_r($vars); echo "</pre>";
		}
		return $vars;
	}


	/**
	 * For the main site Settings. 
	 *
	 * @since 1.0.0
	 * @param none
	 * @return none
	 */
	function process_actions_main_site() {

		global $wp_rewrite;

		if (isset($_POST['bcat'])) {

			$TRIGGER_UPDATE_REWRITE = false;

			if (isset($_POST['bcat']['categories']))
				$this->opts['categories'] = $_POST['bcat']['categories'];
			
			if (isset($_POST['bcat']['sites'])) {
				$this->opts['sites'] = $_POST['bcat']['sites'];

				// Convert the category_excludes from comma-seperated to array (easier to work with)
				if ((isset($this->opts['sites']['category_excludes'])) 
				 && (!empty($this->opts['sites']['category_excludes']))) {

					$cat_excludes = explode(',', $this->opts['sites']['category_excludes']);

					if (($cat_excludes) && (count($cat_excludes))) {
						foreach($cat_excludes as $_idx => $_val) {
							$cat_excludes[$_idx] = trim($_val);
							if (empty($cat_excludes[$_idx]))
								unset($cat_excludes[$_idx]);
						}
						$this->opts['sites']['category_excludes'] = array_values($cat_excludes);
					} else {
						$this->opts['sites']['category_excludes'] = array();
					}
				} else {
					$this->opts['sites']['category_excludes'] = array();
				}
			}
			

			if ((isset($_POST['bcat']['landing_page_id'])) && (intval($_POST['bcat']['landing_page_id']))) {

				$this->opts['landing_page_id'] = $_POST['bcat']['landing_page_id'];
				$this->opts['landing_page_slug'] = get_permalink(intval($this->opts['landing_page_id']));

				if (isset($_POST['bcat']['landing_page_use_rewrite'])) {
					if ($_POST['bcat']['landing_page_use_rewrite'] == "yes")
						$this->opts['landing_page_use_rewrite']	= "yes";
					else
						$this->opts['landing_page_use_rewrite']	= "no";
				}

				// If the landing page is a static home then we set the use rewrite to no.
				if ($this->opts['landing_page_id'] == get_option('page_on_front')) {
					$this->opts['landing_page_use_rewrite']	= "no";
				}
				
				if ( (isset($wp_rewrite)) && ($wp_rewrite->using_permalinks()) )
					$this->opts['landing_page_rewrite'] = true;						
				else
					$this->opts['landing_page_rewrite'] = false;					
				
			} else {
				$this->opts['landing_page_id'] = 0;
				$this->opts['landing_page_slug'] = '';
			}

			if (isset($_POST['bcat']['signups'])) {
				$this->opts['signups'] = $_POST['bcat']['signups'];
			}

			//echo "_POST<pre>"; print_r($_POST); echo "</pre>";
			//die();
						
			$this->save_config();
			$wp_rewrite->flush_rules();			
			
			$location = add_query_arg('message', 'success-settings');
			if ($location) {
				wp_redirect($location);
				die();
			}					
		}
	}
	
	/**
	 * 
	 *
	 * @since 1.0.0
	 * @param none
	 * @return none
	 */
	function process_actions_site() {

		global $wpdb, $current_site, $current_blog;
		
		$CONFIG_CHANGED = false;
		if (isset($_POST['bcat_site_categories'])) {
			
			switch_to_blog( $current_site->blog_id );

			$bcat_site_categories = array();
			if (count($_POST['bcat_site_categories'])) {

				$site_all_categories = array();
				$_cats = wp_get_object_terms($current_blog->blog_id, SITE_CATEGORIES_TAXONOMY);
				if (($_cats) && (is_array($_cats)) && (count($_cats))) {
					foreach($_cats as $_cat) {
						$site_all_categories[$_cat->term_taxonomy_id] = $_cat;
					}
				}

				foreach($_POST['bcat_site_categories'] as $bcat_id) {

					// Double check the selected site categories in case the admin didn't select all items. 
					$bcat_id = intval($bcat_id);
					if ($bcat_id > 0) {
					
						$bcat_term = get_term($bcat_id, SITE_CATEGORIES_TAXONOMY);
						if ( !is_wp_error($bcat_term)) {
							$bcat_site_categories[] = $bcat_term->slug;
							$site_all_categories[$bcat_term->term_taxonomy_id] = $bcat_term;
						}
					}
				}
			}
			$bcat_set = wp_set_object_terms($current_blog->blog_id, $bcat_site_categories, SITE_CATEGORIES_TAXONOMY);

			if (count($site_all_categories)) {
				$this->bcat_taxonomy_terms_count(array_keys($site_all_categories), get_taxonomy(SITE_CATEGORIES_TAXONOMY));
			}

			restore_current_blog();
			$CONFIG_CHANGED = true;
		}

		if (isset($_POST['bcat_site_description'])) {
			$bcat_site_description = esc_attr(stripslashes($_POST['bcat_site_description']));
			update_option('bact_site_description', $bcat_site_description);
			$CONFIG_CHANGED = true;
		}
		
		if ($CONFIG_CHANGED == true) {
			$location = add_query_arg('message', 'success-settings');
			if ($location) {
				wp_redirect($location);
				die();
			}
		}
	}
	
	/**
	 * Display our message on the Snapshot page(s) header for actions taken 
	 *
	 * @since 1.0.0
	 * @uses $this->_messages Set in form processing functions
	 *
	 * @param none
	 * @return none
	 */
	function admin_notices_proc() {
		
		// IF set during the processing logic setsp for add, edit, restore
		if ( (isset($_REQUEST['message'])) && (isset($this->_messages[$_REQUEST['message']])) ) {
			?><div id='user-report-warning' class='updated fade'><p><?php echo $this->_messages[$_REQUEST['message']]; ?></p></div><?php
		}
		
		// IF we set an error display in red box
		if (strlen($this->_admin_header_error))
		{
			?><div id='user-report-error' class='error'><p><?php echo $this->_admin_header_error; ?></p></div><?php
		}
	}
	
	
	/**
	 * Setup our Taxonomy
	 *
	 * @since 1.0.0
	 *
	 * @param none
	 * @return none
	 */
	function register_taxonomy_proc() {
	
		if (!is_multisite())
			return;
		
		// Add new taxonomy, make it hierarchical (like categories)
		$labels = array(
			'name'					=>	_x( 'Seiten-Kategorien', 'taxonomy general name', SITE_CATEGORIES_I18N_DOMAIN ),
			'singular_name'			=>	_x( 'Seiten-Kategorie', 'taxonomy singular name', SITE_CATEGORIES_I18N_DOMAIN ),
			'search_items'			=>	__( 'Suche nach Seiten-Kategorien', SITE_CATEGORIES_I18N_DOMAIN ),
			'all_items'				=>	__( 'Alle Seiten-Kategorien', SITE_CATEGORIES_I18N_DOMAIN ),
			'parent_item'			=>	__( '??bergeordnete Seiten-Kategorie', SITE_CATEGORIES_I18N_DOMAIN ),
			'parent_item_colon'		=>	__( '??bergeordnete Seiten-Kategorie:', SITE_CATEGORIES_I18N_DOMAIN ),
			'edit_item'				=>	__( 'Seiten-Kategorie bearbeiten', SITE_CATEGORIES_I18N_DOMAIN ), 
			'update_item'			=>	__( 'Seiten-Kategorie aktualisieren', SITE_CATEGORIES_I18N_DOMAIN ),
			'add_new_item'			=>	__( 'Neue Seiten-Kategorie hinzuf??gen', SITE_CATEGORIES_I18N_DOMAIN ),
			'new_item_name'			=>	__( 'Name der neuen Seiten-Kategorie', SITE_CATEGORIES_I18N_DOMAIN ),
			'menu_name'				=>	__( 'Seiten-Kategorie', SITE_CATEGORIES_I18N_DOMAIN ),
		);	


		if (is_super_admin()) {
			$show_ui	= true;
			$query_var	= true;
			$rewrite	= array( 'slug' => SITE_CATEGORIES_TAXONOMY );
		}
		else {
			$show_ui	= false;
			$query_var	= false;
			$rewrite	= '';
		}
			
		register_taxonomy(SITE_CATEGORIES_TAXONOMY, null, array(
			'hierarchical'				=>	true,
			'update_count_callback'		=>	array($this, 'bcat_taxonomy_terms_count'),
			'labels'					=>	$labels,
			'show_ui'					=>	$show_ui,
			'query_var'					=>	$query_var,
			'rewrite'					=>	$rewrite
		));
	}
	
	/**
	 * 
	 *
	 * @since 1.0.0
	 *
	 * @param none
	 * @return none
	 */
	function bcat_taxonomy_terms_count($tt_ids, $taxonomy) {
		global $wpdb, $current_site, $current_blog; 
		
		if ($taxonomy->name != SITE_CATEGORIES_TAXONOMY) return;
		
		switch_to_blog( $current_site->blog_id );

		foreach($tt_ids as $tt_id) {
			//$sql_str = $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->term_relationships WHERE term_taxonomy_id = %d", $tt_id );
			$sql_str = $wpdb->prepare( "SELECT COUNT( $wpdb->blogs.blog_id ) as count FROM $wpdb->term_relationships LEFT JOIN $wpdb->blogs ON $wpdb->term_relationships.object_id = $wpdb->blogs.blog_id WHERE $wpdb->term_relationships.term_taxonomy_id =%d AND $wpdb->blogs.blog_id IS NOT NULL AND $wpdb->blogs.public = 1 AND $wpdb->blogs.archived = '0' AND $wpdb->blogs.mature = 0 AND $wpdb->blogs.spam = 0 AND $wpdb->blogs.deleted = 0", $tt_id );
			
			
			//echo "sql_str=[". $sql_str ."]<br />";
			$count = $wpdb->get_var( $sql_str );
			//echo "count=[". $count ."]<br />";
			//die();

			$wpdb->update( $wpdb->term_taxonomy, array('count' => $count ), array( 'term_taxonomy_id' => $tt_id ) );
		}
		restore_current_blog();		
	}
	
	/**
	 * Handled the delete blog actions (archive, delete, Deactivate, Spam). Remove the blog site categories. 
	 *
	 * @since 1.0.7.2
	 *
	 * @param none
	 * @return none
	 */
	function blog_change_status_count($blog_id) {
		global $wpdb, $current_site;

		if (!$blog_id) return;
		if (!(isset($_GET['action']))) return;
		
		$blog_state_action = esc_attr($_GET['action']);

		switch_to_blog( $current_site->blog_id );

		switch($blog_state_action) {
			case 'deleteblog':
				wp_delete_object_term_relationships($blog_id, SITE_CATEGORIES_TAXONOMY);
				break;
			
			case 'spamblog':
			case 'mature_blog':
			case 'archiveblog':
			case 'deactivateblog':
				$terms = wp_get_object_terms( $blog_id, SITE_CATEGORIES_TAXONOMY);
				if ( (!is_wp_error($terms)) && ($terms) && (is_array($terms)) && (count($terms))) {
					foreach($terms as $term) {

						$term_sites = $this->get_taxonomy_sites($term->term_id);
						if ((!$term_sites) || (!is_array($term_sites)))
							$term_sites = array();

						if (isset($term_sites[$blog_id]))
							unset($term_sites[$blog_id]);
								
						$terms_count = count($term_sites);
						$wpdb->update( $wpdb->term_taxonomy, array('count' => $terms_count ), array( 'term_taxonomy_id' => $term->term_taxonomy_id ) );
					}
				}
				break;
				
			case 'unspamblog':
			case 'unarchiveblog':
			case 'activateblog':
				$terms = wp_get_object_terms( $blog_id, SITE_CATEGORIES_TAXONOMY);
				if ( (!is_wp_error($terms)) && ($terms) && (is_array($terms)) && (count($terms))) {
					foreach($terms as $term) {

						$term_sites = $this->get_taxonomy_sites($term->term_id);
						if ((!$term_sites) || (!is_array($term_sites)))
							$term_sites = array();

						if (isset($term_sites[$blog_id]))
							unset($term_sites[$blog_id]);

						$terms_count = count($term_sites);

						$blog = get_blog_details($blog_id);
						if (($blog) && ($blog->public != 0) && ($blog->archived == 0) && ($blog->spam == 0) && ($blog->deleted == 0) && ($blog->mature == 0)) {
							$terms_count += 1;
						}
						$wpdb->update( $wpdb->term_taxonomy, array('count' => $terms_count ), array( 'term_taxonomy_id' => $term->term_taxonomy_id ) );
					}
				}
				break;
		}

		restore_current_blog();
	}
	
	
	/**
	 * Add the new Menu to the Tools section in the WordPress main nav
	 *
	 * @since 1.0.0
	 * @uses $this->_pagehooks 
	 * @see $this->__construct where this function is referenced
	 *
	 * @param none
	 * @return none
	 */
	function admin_menu_proc() {

		if (!is_multisite()) 
			return;

		if ((is_main_site()) && (is_super_admin())) {

			$page_hook = add_menu_page( _x("Seiten-Kategorien", 'page label', SITE_CATEGORIES_I18N_DOMAIN), 
							_x("Seiten-Kategorien", 'menu label', SITE_CATEGORIES_I18N_DOMAIN),
							'manage_options',
							'bcat_settings',
							array(&$this, 'settings_panel_main_site')
			);

			$this->_pagehooks['site-categories-settings-main-site'] = add_submenu_page( 
						'bcat_settings', 
						_x('Einstellungen','page label', SITE_CATEGORIES_I18N_DOMAIN), 
						_x('Einstellungen', 'menu label', SITE_CATEGORIES_I18N_DOMAIN), 
						'manage_options',
						'bcat_settings', 
						array(&$this, 'settings_panel_main_site')
			);

			$this->_pagehooks['site_categories-terms'] = add_submenu_page( 
						'bcat_settings', 
						_x('Seiten-Kategorien','page label', SITE_CATEGORIES_I18N_DOMAIN), 
						_x('Seiten-Kategorien', 'menu label', SITE_CATEGORIES_I18N_DOMAIN), 
						'manage_options',
						'edit-tags.php?taxonomy=bcat'
			);

			// Hook into the WordPress load page action for our new nav items. This is better then checking page query_str values.
			add_action('load-'. $this->_pagehooks['site-categories-settings-main-site'],		array(&$this, 'on_load_page_main_site'));
		
		} 
		
		$this->_pagehooks['site-categories-settings-site'] = add_options_page(
			_x("Seiten-Kategorien", 'page label', SITE_CATEGORIES_I18N_DOMAIN), 
			_x("Seiten-Kategorien", 'menu label', SITE_CATEGORIES_I18N_DOMAIN),
			'manage_options', 
			'bcat_settings_site', 
			array(&$this, 'settings_panel_site')
		);
	
		add_action('load-'. $this->_pagehooks['site-categories-settings-site'],			array(&$this, 'on_load_page_site'));	
	}

	/**
	 * 
	 *
	 * @since 1.0.0
	 *
	 * @param none
	 * @return none
	 */
	function on_load_page_main_site() {
		
		if ( ! current_user_can( 'manage_options' ) )
			wp_die( __( 'Schummeln&#8217; was?' ) );

		$this->_messages['success-settings']			= __( "Die Einstellungen wurden aktualisiert.", SITE_CATEGORIES_I18N_DOMAIN );

		$this->load_config();
		$this->process_actions_main_site();
		$this->admin_plugin_help();

		wp_enqueue_script('common');
		wp_enqueue_script('wp-lists');
		wp_enqueue_script('postbox');
		
		wp_register_script('site-categories-admin', plugins_url('/js/jquery.site-categories-admin.js', __FILE__), 
			array('jquery'), $this->_settings['VERSION']  );
		wp_enqueue_script('site-categories-admin');
		
		// Now add our metaboxes
		add_meta_box('site-categories-settings-main-admin-display_options-panel', 
			__('Auswahl der Landeseite', SITE_CATEGORIES_I18N_DOMAIN), 
			array(&$this, 'settings_main_admin_display_options_panel'), 
			$this->_pagehooks['site-categories-settings-main-site'], 
			'normal', 'core');

		add_meta_box('site-categories-settings-main-admin-display_selection-options-panel', 
			__('Auswahloptionen f??r Seiten-Kategorien', SITE_CATEGORIES_I18N_DOMAIN), 
			array(&$this, 'settings_main_admin_display_selection_options_panel'), 
			$this->_pagehooks['site-categories-settings-main-site'], 
			'normal', 'core');

		add_meta_box('site-categories-settings-main-categories-display-options-panel', 
			__('Anzeigeoptionen f??r Landeseite Kategorien', SITE_CATEGORIES_I18N_DOMAIN), 
			array(&$this, 'settings_main_categories_display_options_panel'), 
			$this->_pagehooks['site-categories-settings-main-site'], 
			'normal', 'core');

		add_meta_box('site-categories-settings-main-sites-display-options-panel', 
			__('Anzeigeoptionen f??r Landeseiten', SITE_CATEGORIES_I18N_DOMAIN), 
			array(&$this, 'settings_main_sites_display_options_panel'), 
			$this->_pagehooks['site-categories-settings-main-site'], 
			'normal', 'core');

		add_meta_box('site-categories-settings-main-sites-signup-form-options-panel', 
			__('Optionen f??r das Neue Seite-Anmeldeformular', SITE_CATEGORIES_I18N_DOMAIN), 
			array(&$this, 'settings_main_sites_signup_form_options_panel'), 
			$this->_pagehooks['site-categories-settings-main-site'], 
			'normal', 'core');

	}


	/**
	 * 
	 *
	 * @since 1.0.0
	 *
	 * @param none
	 * @return none
	 */
	function on_load_page_site() {
		
		if ( ! current_user_can( 'manage_options' ) )
			wp_die( __( 'Cheatin&#8217; uh?' ) );

		$this->_messages['success-settings']			= __( "Die Einstellungen wurden aktualisiert.", SITE_CATEGORIES_I18N_DOMAIN );

		$this->load_config();
		$this->process_actions_site();
		$this->site_plugin_help();
		
		wp_enqueue_script('common');
		wp_enqueue_script('wp-lists');
		wp_enqueue_script('postbox');
				
		// Now add our metaboxes
		add_meta_box('site-categories-settings-site-categories-panel', 
			__('W??hle die Kategorien f??r diese Seite aus', SITE_CATEGORIES_I18N_DOMAIN), 
			array(&$this, 'settings_site_select_categories_panel'), 
			$this->_pagehooks['site-categories-settings-site'], 
			'normal', 'core');

		add_meta_box('site-categories-settings-site-description-panel', 
			__('Seitenbeschreibung', SITE_CATEGORIES_I18N_DOMAIN), 
			array(&$this, 'settings_site_description_panel'), 
			$this->_pagehooks['site-categories-settings-site'], 
			'normal', 'core');

	}


	/**
	 * 
	 *
	 * @since 1.0.0
	 *
	 * @param none
	 * @return none
	 */
	function admin_plugin_help() {
		global $wp_version;
				
		$screen = get_current_screen();
		//echo "screen<pre>"; print_r($screen); echo "</pre>";
		
		$screen_help_text = array();
		
		/**
		Left navigation list
		*/
		$screen_help_text['site-categories-help-overview'] = '<p>' . __( 'Dieses Einstellungsfeld steuert verschiedene Anzeigeoptionen f??r die Lande-Seite. Diese Zielseite wird nur auf der prim??ren Seite gehostet. ??ber die Optionen auf dieser Seite kannst Du das Layout der Seiten-Kategorienelemente steuern.', SITE_CATEGORIES_I18N_DOMAIN ) . '</p>';

		$screen_help_text['site-categories-help-overview'] .= "<ul>";

		$screen_help_text['site-categories-help-overview'] .= '<li><strong>'. __('Auswahl der Landeseite', SITE_CATEGORIES_I18N_DOMAIN) .'</strong> - '. __('Mit dieser Auswahl kannst Du die Zielseite festlegen, die beim Anzeigen der Seiten-Kategorien verwendet werden soll.', SITE_CATEGORIES_I18N_DOMAIN) .'</li>';

		$screen_help_text['site-categories-help-overview'] .= '<li><strong>'. __('Auswahloptionen f??r Seiten-Kategorien', SITE_CATEGORIES_I18N_DOMAIN) .'</strong> - '. __('Mit dieser Auswahl kannst Du steuern, wie die Seiten-Kategorien von anderen Seiten-Administratorbenutzern angezeigt und ausgew??hlt werden.', SITE_CATEGORIES_I18N_DOMAIN) .'</li>';

		$screen_help_text['site-categories-help-overview'] .= '<li><strong>'. __('Anzeigeoptionen f??r Landeseitenkategorien', SITE_CATEGORIES_I18N_DOMAIN) .'</strong> - '. __('Diese Auswahl steuert die Ausgabe der Seiten-Kategorien auf der Landeseite. Hier kannst Du den Stil, die Symbole, die Anzahl der Kategorien pro Seite usw. steuern.', SITE_CATEGORIES_I18N_DOMAIN) .'</li>';

		$screen_help_text['site-categories-help-overview'] .= '<li><strong>'. __('Anzeigeoptionen f??r Landeseiten', SITE_CATEGORIES_I18N_DOMAIN) .'</strong> - '. __('Diese Auswahl steuert die Ausgabe der Seiten auf der Landeseite. Hier kannst Du den Stil, die Symbole, die Anzahl der Webseiten pro Seite usw. steuern.', SITE_CATEGORIES_I18N_DOMAIN) .'</li>';
		
		$screen_help_text['site-categories-help-overview'] .= '<li><strong>'. __('Neue Optionen f??r das Seiten-Anmeldeformular', SITE_CATEGORIES_I18N_DOMAIN) .'</strong> - '. __('Diese Auswahl steuert, wie die Optionen f??r Seiten-Kategorien im Anmeldeformular f??r neue Seiten angezeigt werden.', SITE_CATEGORIES_I18N_DOMAIN) .'</li>';
		
		$screen_help_text['site-categories-help-overview'] .= "</ul>";


		/**
		Landing Page Selection
		*/
		$screen_help_text['site-categories-help-settings-landing'] = '<p>'. __('Mit der Zielseitenauswahl kannst Du die Landeseite festlegen, die beim Anzeigen der Seiten-Kategorien verwendet werden soll.', SITE_CATEGORIES_I18N_DOMAIN). '</p>';
		$screen_help_text['site-categories-help-settings-landing'] .= '<ul>';
			
		$screen_help_text['site-categories-help-settings-landing'] .= '<li><strong>'. __('Landeseite ausw??hlen', SITE_CATEGORIES_I18N_DOMAIN). '</strong> - '. __('W??hle die Seite aus, die als Landeseite f??r Seiten-Kategorien fungieren soll. Die Zielseite wird automatisch am unteren Rand des Seiteninhalts eingef??gt.', SITE_CATEGORIES_I18N_DOMAIN). '</li>';
		$screen_help_text['site-categories-help-settings-landing'] .= '</ul>';
	
	
		/**
		Site Categories Selection Options
		*/	
		$screen_help_text['site-categories-help-settings-selection'] = '<p>'. __('Mit dieser Auswahl kannst Du steuern, wie die Seiten-Kategorien von anderen Seiten-Administratorbenutzern angezeigt und ausgew??hlt werden.', SITE_CATEGORIES_I18N_DOMAIN).'</p>';
		$screen_help_text['site-categories-help-settings-selection'] .= '<ul>';
		
		$screen_help_text['site-categories-help-settings-selection'] .= '<li><strong>'. __('Anzahl der Kategorien pro Seite', SITE_CATEGORIES_I18N_DOMAIN). '</strong> - '. __(' Diese Option steuert die Anzahl der Dropdown-Selektoren, die der Seiten-Administrator beim Erstellen einer neuen Seite oder unter der Option Seiten-Kategorie-Einstellungen innerhalb einer vorhandenen Seite sieht.', SITE_CATEGORIES_I18N_DOMAIN). '</li>';

		$screen_help_text['site-categories-help-settings-selection'] .= '<li><strong>'. __('Anzahl der Kategorien pro Seite', SITE_CATEGORIES_I18N_DOMAIN). '</strong> - '. __('Dies steuert die Anzahl der Kategorien, die eine Seite festlegen kann. Im Bereich "Seiten-Einstellungen" werden dem Administrator eine Reihe von Dropdown-Listen f??r die Seiten-Kategorien angezeigt. Der Administrator kann eine oder mehrere davon auf die verf??gbaren Seiten-Kategorien festlegen.', SITE_CATEGORIES_I18N_DOMAIN). '</li>';
		
		$screen_help_text['site-categories-help-settings-selection'] .= '<li><strong>'. __('Bloghosting', SITE_CATEGORIES_I18N_DOMAIN). '</strong> - '. sprintf(__('Wenn Du das Plugin %1$sBlog Hosting%2$s installiert hast, kannst Du jeder Ebene eine andere Anzahl von Seiten-Kategorien zuweisen', SITE_CATEGORIES_I18N_DOMAIN), '<a href="https://n3rds.work/shop/artikel/category/psource-ro/" target="_blank">', '</a>'). '</li>';

			$screen_help_text['site-categories-help-settings-selection'] .= '<li><strong>'. __('Kategorie ??bergeordnete w??hlbar', SITE_CATEGORIES_I18N_DOMAIN). '</strong> - '. __('Mit dieser Option kannst Du die Auswahl nur untergeordneter Kategorien aus den Dropdown-Selektoren erzwingen. Dies ist praktisch, wenn Du Deine Seiten-Kategorien mithilfe des Rasterlayouts anzeigen.', SITE_CATEGORIES_I18N_DOMAIN) . '</li>';
			
		$screen_help_text['site-categories-help-settings-selection'] .= '</ul>';
		
		
		/**
		Landing Page Categories Display Options 
		*/		
		$screen_help_text['site-categories-help-settings-landing-categories'] = '<p>'. __('Diese Auswahl steuert die Ausgabe der Seiten-Kategorien auf der Zielseite. Hier kannst Du den Stil, die Symbole, die Anzahl der Kategorien pro Seite usw. steuern.', SITE_CATEGORIES_I18N_DOMAIN) .'</p>';
		$screen_help_text['site-categories-help-settings-landing-categories'] .= '<ul>';
		$screen_help_text['site-categories-help-settings-landing-categories'] .= '<li><strong>'. __('Anzeigestil', SITE_CATEGORIES_I18N_DOMAIN) .'</strong> - '. __('Der Anzeigestil gibt an, wie die Seiten-Kategorien auf der Seite dargestellt werden. In der Dropdown-Liste kannst Du eine einfache Liste ausw??hlen oder erweiterte Anzeigeoptionen wie Raster oder Akkordeon ausprobieren.', SITE_CATEGORIES_I18N_DOMAIN) .'</li>';

		$screen_help_text['site-categories-help-settings-landing-categories'] .= '<li><strong>'. __('Kategorien pro Seite', SITE_CATEGORIES_I18N_DOMAIN) .'</strong> - '. __('Dies ist die Anzahl der Kategorien, die auf einer bestimmten Seite angezeigt werden sollen. Wenn Du Hunderte von Seiten-Kategorien hast, m??chtest Du wahrscheinlich nicht, dass diese alle auf einer einzigen Seite angezeigt werden. Das w??ren zu viele Informationen, als dass der Benutzer sie verdauen k??nnte. So kannst Du die Anzahl der Kategorien auf etwas Verwaltbares wie 20, 50 oder 100 einstellen.', SITE_CATEGORIES_I18N_DOMAIN) .'</li>';

		$screen_help_text['site-categories-help-settings-landing-categories'] .= '<li><strong>'. __('Ordnen nach', SITE_CATEGORIES_I18N_DOMAIN) .'</strong> - '. __('Standardm????ig werden die angezeigten Seiten-Kategorien nach Name sortiert. Mit dieser Option kannst Du die Reihenfolge nach Deinen W??nschen anpassen.', SITE_CATEGORIES_I18N_DOMAIN) .'</li>';
		
		$screen_help_text['site-categories-help-settings-landing-categories'] .= '<li><strong>'. __('Leere Kategorien ausblenden', SITE_CATEGORIES_I18N_DOMAIN) .'</strong> - '. __('Wenn einer Seiten-Kategorie keine Seiten zugewiesen sind, m??chtedt Du sie m??glicherweise aus der Liste ausblenden.', SITE_CATEGORIES_I18N_DOMAIN) .'</li>';

		$screen_help_text['site-categories-help-settings-landing-categories'] .= '<li><strong>'. __('Z??hler anzeigen', SITE_CATEGORIES_I18N_DOMAIN) .'</strong> - '. __('??hnlich wie beim Ausblenden kannst Du dem Benutzer mit dieser Option anzeigen, wie viele Webseiten jeder Webseitenkategorie zugeordnet sind.', SITE_CATEGORIES_I18N_DOMAIN) .'</li>';
		
		$screen_help_text['site-categories-help-settings-landing-categories'] .= '<li><strong>'. __('Kategoriebeschreibung anzeigen', SITE_CATEGORIES_I18N_DOMAIN) .'</strong> - '. __('Wenn Du die Seiten-Kategorien erstellst, kannst Du eine detaillierte Beschreibung bereitstellen. Diese Beschreibung kann als Teil der Anzeigeausgabe angezeigt werden. ', SITE_CATEGORIES_I18N_DOMAIN) .'</li>';
		
		$screen_help_text['site-categories-help-settings-landing-categories'] .= '<li><strong>'. __('Symbole anzeigen', SITE_CATEGORIES_I18N_DOMAIN) .'</strong> - '. __('Im Rahmen der Einrichtung der Seiten-Kategorien kannst Du ein Bild hochladen oder ausw??hlen, um die Seiten-Kategorie darzustellen. Wenn Du diese Option verwendest, werden diese Symbole als Teil der Anzeigeausgabe angezeigt.', SITE_CATEGORIES_I18N_DOMAIN) .'</li>';
		
		$screen_help_text['site-categories-help-settings-landing-categories'] .= '<li><strong>'. __('Symbolgr????e', SITE_CATEGORIES_I18N_DOMAIN) .'</strong> - '. __('Wenn Du Seiten-Kategoriesymbole anzeigen m??chtest, kannst Du die Gr????e dieser Symbole mit dieser Option steuern.', SITE_CATEGORIES_I18N_DOMAIN) .'</li>';

		$screen_help_text['site-categories-help-settings-landing-categories'] .= '</ul>';


		/**
		Landing Page Sites Display Options 
		*/			
		$screen_help_text['site-categories-help-settings-landing-sites'] = '<p>'. __('Diese Auswahl steuert die Ausgabe der Seiten auf der Landeseite. Hier kannst Du den Stil, die Symbole, die Anzahl der Webseiten pro Seite usw. steuern.', SITE_CATEGORIES_I18N_DOMAIN) .'</p>';
		$screen_help_text['site-categories-help-settings-landing-sites'] .= '<ul>';
		$screen_help_text['site-categories-help-settings-landing-sites'] .= '<li><strong>'. __('Anzeigestil', SITE_CATEGORIES_I18N_DOMAIN) .'</strong> - '. __('Der Anzeigestil gibt an, wie die Webseiten auf der Seite dargestellt werden.', SITE_CATEGORIES_I18N_DOMAIN) .'</li>';

		$screen_help_text['site-categories-help-settings-landing-sites'] .= '<li><strong>'. __('Webseiten pro Seite', SITE_CATEGORIES_I18N_DOMAIN) .'</strong> - '. __('Dies ist die Anzahl der Webseiten, die auf einer bestimmten Seite angezeigt werden sollen. Wenn Du Hunderte von Webseiten in einer einzigen Kategorie hast, m??chtest Du wahrscheinlich nicht, dass diese alle auf einer einzigen Seite angezeigt werden. Das w??ren zu viele Informationen, als dass der Benutzer sie verdauen k??nnte. Du kannst also die Anzahl der Webseiten auf ??berschaubare Werte wie 20, 50 oder 100 einstellen.', SITE_CATEGORIES_I18N_DOMAIN) .'</li>';

		$screen_help_text['site-categories-help-settings-landing-sites'] .= '<li><strong>'. __('Seiten-Beschreibung anzeigen', SITE_CATEGORIES_I18N_DOMAIN) .'</strong> - '. __('Auf der Seite Seiten-Administratoreinstellungen kann der Administrator eine Beschreibung f??r die Seite eingeben. Dies ??hnelt der Beschreibung der Seiten-Kategorie. Wenn von der Seite bereitgestellt, wird dies als Teil der Seitenausgabe angezeigt.', SITE_CATEGORIES_I18N_DOMAIN) .'</li>';

		$screen_help_text['site-categories-help-settings-landing-sites'] .= '<li><strong>'. __('Symbole anzeigen', SITE_CATEGORIES_I18N_DOMAIN) .'</strong> - '. sprintf(__('Wenn das Plugin %1$sAvatare%2$s installiert ist, kannst Du das Seiten-Symbol als Teil der Anzeigeausgabe anzeigen.', SITE_CATEGORIES_I18N_DOMAIN), 
			'<a href="https://n3rds.work/wiki/piestingtal-source-wiki/avatare-plugin/" target="_blank">', '</a>'). '</li>';
		
		$screen_help_text['site-categories-help-settings-landing-sites'] .= '<li><strong>'. __('Symbolgr????e', SITE_CATEGORIES_I18N_DOMAIN) .'</strong> - '. __('Wenn Du Seiten-Symbole anzeigen m??chtest, kannst Du die Gr????e dieser Symbole mit dieser Option steuern.', SITE_CATEGORIES_I18N_DOMAIN) .'</li>';
		
		$screen_help_text['site-categories-help-settings-landing-sites'] .= '</ul>';


		/**
		New Site Signup Form Options
		*/
		$screen_help_text['site-categories-help-signup-form'] = '<p>'. __('Diese Auswahl steuert, wie die Optionen f??r Seiten-Kategorien im Anmeldeformular f??r neue Seiten angezeigt werden.', SITE_CATEGORIES_I18N_DOMAIN) .'</p>';
		$screen_help_text['site-categories-help-signup-form'] .= '<ul>';
		$screen_help_text['site-categories-help-signup-form'] .= '<li><strong>'. __('Abschnitt Seiten-Kategorien anzeigen', SITE_CATEGORIES_I18N_DOMAIN) .'</strong> - '. __('Mit dieser Option kannst Du die Anzeige der Dropdown-Listen und der Beschreibung der Seiten-Kategorien im neuen Seiten-Anmeldeformular steuern.', SITE_CATEGORIES_I18N_DOMAIN) .'</li>';
		$screen_help_text['site-categories-help-signup-form'] .= '<li><strong>'. __('Auswahl der Seiten-Kategorien erforderlich', SITE_CATEGORIES_I18N_DOMAIN) .'</strong> - '. __('Erm??glicht es den neuen Seiten-Administrator zu zwingen, Optionen f??r Seiten-Kategorien auszuw??hlen. Wenn festgelegt, muss der Administrator festlegen ', SITE_CATEGORIES_I18N_DOMAIN) .'</li>';
		$screen_help_text['site-categories-help-signup-form'] .= '<li><strong>'. __('Beschriftung f??r Dropdown-Listen f??r Seiten-Kategorien', SITE_CATEGORIES_I18N_DOMAIN) .'</strong> - '. __('Mit dieser Option kannst Du eine alternative Formularbezeichnung f??r die Dropdown-Auswahl "Seiten-Kategorien" verwenden. Vielleicht etwas Beschreibenderes f??r den Benutzer.', SITE_CATEGORIES_I18N_DOMAIN) .'</li>';
		$screen_help_text['site-categories-help-signup-form'] .= '<li><strong>'. __('Beschreibung ist erforderlich', SITE_CATEGORIES_I18N_DOMAIN) .'</strong> - '. __('Diese Option steuert, ob das Textfeld Seitenbeschreibung im Formular erforderlich ist.', SITE_CATEGORIES_I18N_DOMAIN) .'</li>';
		$screen_help_text['site-categories-help-signup-form'] .= '<li><strong>'. __('Beschriftung f??r Seiten-Kategorien Beschreibung', SITE_CATEGORIES_I18N_DOMAIN) .'</strong> - '. __('Mit dieser Option kannst Du eine alternative Formularbezeichnung f??r das Feld Seitenbeschreibung verwenden.', SITE_CATEGORIES_I18N_DOMAIN) .'</li>';
		$screen_help_text['site-categories-help-signup-form'] .= '</ul>';


		if ( version_compare( $wp_version, '3.3.0', '>' ) ) {
			
			if ((isset($_REQUEST['page'])) && ($_REQUEST['page'] == "bcat_settings")) {
		
				$screen->add_help_tab( array(
					'id'		=> 'site-categories-help-overview',
					'title'		=> __('Einstellungs??bersicht', SITE_CATEGORIES_I18N_DOMAIN ),
					'content'	=>	$screen_help_text['site-categories-help-overview']
					) 
				);

				$screen->add_help_tab( array(
					'id'		=> 'site-categories-help-settings-landing',
					'title'		=> __('Auswahl der Landeseite', SITE_CATEGORIES_I18N_DOMAIN ),
					'content'	=>	$screen_help_text['site-categories-help-settings-landing']
					) 
				);

				$screen->add_help_tab( array(
					'id'		=> 'site-categories-help-settings-selection',
					'title'		=> __('Auswahloptionen f??r Seiten-Kategorien', SITE_CATEGORIES_I18N_DOMAIN ),
					'content'	=>	$screen_help_text['site-categories-help-settings-selection']
					) 
				);
				
				$screen->add_help_tab( array(
					'id'		=> 'site-categories-help-settings-landing-categories',
					'title'		=> __('Anzeigeoptionen f??r Kategorien', SITE_CATEGORIES_I18N_DOMAIN ),
					'content'	=>	$screen_help_text['site-categories-help-settings-landing-categories']
					) 
				);

				$screen->add_help_tab( array(
					'id'		=> 'site-categories-help-settings-landing-sites',
					'title'		=> __('Anzeigeoptionen f??r Webseiten', SITE_CATEGORIES_I18N_DOMAIN ),
					'content'	=>	$screen_help_text['site-categories-help-settings-landing-sites']
					) 
				);

				$screen->add_help_tab( array(
					'id'		=> 'site-categories-help-signup-form',
					'title'		=> __('Neue Optionen f??r das Seiten-Anmeldeformular', SITE_CATEGORIES_I18N_DOMAIN ),
					'content'	=>	$screen_help_text['site-categories-help-signup-form']
					) 
				);
				
			}			
		} 
	}


	/**
	 * 
	 *
	 * @since 1.0.0
	 *
	 * @param none
	 * @return none
	 */
	function site_plugin_help() {
		global $wp_version;

		$screen = get_current_screen();
		//echo "screen<pre>"; print_r($screen); echo "</pre>";

		$screen_help_text = array();

		$screen_help_text['site-categories-page-settings'] = '<p>' . __( 'Auf dieser Seite kannst Du diese Seite verschiedenen Seiten-Kategorien zuordnen. Die Seiten-Kategorien sind global f??r dieses Multisite-Netzwerk von Seiten und Gesch??ften innerhalb des prim??ren Standorts.', SITE_CATEGORIES_I18N_DOMAIN). '</p>';
		$screen_help_text['site-categories-page-settings'] .= '<ul>';
		$screen_help_text['site-categories-page-settings'] .= '<li><strong>'. __('W??hle die Kategorien f??r diese Seite aus', SITE_CATEGORIES_I18N_DOMAIN) .'</strong> - '. __('Abh??ngig von der Anzahl der vom Superadministrator zugelassenen Kategorien wird eine Reihe von Dropdown-Listen angezeigt, in denen Du die Seiten-Kategorie ausw??hlen kannst, der diese Seite zugeordnet werden soll.', SITE_CATEGORIES_I18N_DOMAIN) .'</li>';
		$screen_help_text['site-categories-page-settings'] .= '<li><strong>'. __('Seitenbeschreibung', SITE_CATEGORIES_I18N_DOMAIN) .'</strong> - '. __('Auch auf dieser Seite kannst Du eine optionale Site-Beschreibung eingeben. Die Seiten-Beschreibung wird auf der Landeseite Seiten-Kategorien der prim??ren Seite verwendet.', SITE_CATEGORIES_I18N_DOMAIN ) . '</li>';


		if ( version_compare( $wp_version, '3.3.0', '>' ) ) {

			if ((isset($_REQUEST['page'])) && ($_REQUEST['page'] == "bcat_settings_site")) {

				$screen->add_help_tab( array(
					'id'		=> 'site-categories-page-settings',
					'title'		=> __('Einstellungs??bersicht', SITE_CATEGORIES_I18N_DOMAIN ),
					'content'	=>	$screen_help_text['site-categories-page-settings']
					) 
				);
			}			
		} 
	}
	
	/**
	 * Metabox showing form for Settings.
	 *
	 * @since 1.0.0
	 *
	 * @param none
	 * @return none
	 */		
	function settings_panel_main_site() {

		?>
		<div id="site-categories-panel" class="wrap site-categories-wrap">
			<?php screen_icon(); ?>
			<h2><?php _ex("Einstellungen f??r Seiten-Kategorien", "Seiten-Kategorien Neuer Seitentitel", SITE_CATEGORIES_I18N_DOMAIN); ?></h2>

			<div id="poststuff" class="metabox-holder">
				<div id="post-body" class="">
					<div id="post-body-content" class="site-categories-metabox-holder-main">
						<form id="bcat_settings_form" action="<?php echo admin_url('admin.php?page=bcat_settings'); ?>" method="post">
							<?php do_meta_boxes($this->_pagehooks['site-categories-settings-main-site'], 'normal', ''); ?>
							<input class="button-primary" type="submit" value="<?php _e('Einstellungen speichern', SITE_CATEGORIES_I18N_DOMAIN); ?>" />
						</form>
					</div>
				</div>
			</div>	
		</div>
		<script type="text/javascript">
			//<![CDATA[
			jQuery(document).ready( function($) {
				// close postboxes that should be closed
				$('.if-js-closed').removeClass('if-js-closed').addClass('closed');

				// postboxes setup
				postboxes.add_postbox_toggles('<?php echo $this->_pagehooks['site-categories-settings-main-site']; ?>');
			});
			//]]>
		</script>
		<?php
	}

	/**
	 * 
	 *
	 * @since 1.0.0
	 *
	 * @param none
	 * @return none
	 */
	function settings_panel_site() {

		?>
		<div id="site-categories-panel" class="wrap site-categories-wrap">
			<?php screen_icon(); ?>
			<h2><?php _ex("Netzwerkseiten Kategorien", "Seiten-Kategorien Neuer Seitentitel", SITE_CATEGORIES_I18N_DOMAIN); ?></h2>

			<div id="poststuff" class="metabox-holder">
				<div id="post-body" class="">
					<div id="post-body-content" class="site-categories-metabox-holder-main">
						<p><?php _e('Aus den folgenden Optionen kannst Du die Seiten-Kategorien ausw??hlen, die Deinr Seite am besten beschreiben. Gib au??erdem eine Beschreibung an, die auf der Zielseite f??r Seiten-Kategorien angezeigt werden kann.', SITE_CATEGORIES_I18N_DOMAIN); ?><?php
							if (isset($this->opts['landing_page_slug'])) {
								?> <a href="<?php echo $this->opts['landing_page_slug']; ?>" target="_blank"><?php 
									_e('Zeige die Zielseite "Seiten-Kategorien" an.', SITE_CATEGORIES_I18N_DOMAIN); ?></a>>
								<?php
							}
						?></p>

						<form id="bcat_settings_form" action="<?php echo admin_url('options-general.php?page=bcat_settings_site'); ?>" method="post">
							<?php do_meta_boxes($this->_pagehooks['site-categories-settings-site'], 'normal', ''); ?>
							<input class="button-primary" type="submit" value="<?php _e('Einstellungen speichern', SITE_CATEGORIES_I18N_DOMAIN); ?>" />
						</form>
					</div>
				</div>
			</div>	
		</div>
		<script type="text/javascript">
			//<![CDATA[
			jQuery(document).ready( function($) {
				// close postboxes that should be closed
				$('.if-js-closed').removeClass('if-js-closed').addClass('closed');

				// postboxes setup
				postboxes.add_postbox_toggles('<?php echo $this->_pagehooks['site-categories-settings-site']; ?>');
			});
			//]]>
		</script>
		<?php
	}
	
	/**
	 * 
	 *
	 * @since 1.0.0
	 *
	 * @param none
	 * @return none
	 */
	function settings_main_categories_display_options_panel() {

		if (($this->opts['categories']['show_style'] != "accordion") && ($this->opts['categories']['show_style'] != "grid")) { 
			$display_grid_accordion_options = "display: none;";
		} else {
			$display_grid_accordion_options = "";
		}

		?>
		<table class="form-table">
		<tr class="form-field" >
			<th scope="row">
				<label for="site-categories-show-style"><?php _e('Anzeigestil', SITE_CATEGORIES_I18N_DOMAIN); ?></label>
			</th>
			<td>
				<div class="site-categories-parent-child-left">
					<p class="site-categories-accordion-options site-categories-grid-options" style="<?php echo $display_grid_accordion_options; ?>"><?php _e('??bergeordnete',  SITE_CATEGORIES_I18N_DOMAIN); ?></p>
				
					<select id="site-categories-show-style" name="bcat[categories][show_style]">
						<option value="ul" <?php if ($this->opts['categories']['show_style'] == "ul") { 
							echo 'selected="selected" '; } ?>><?php _e('Ungeordnete Liste (ul)', SITE_CATEGORIES_I18N_DOMAIN); ?></option>
						<option value="ul-nested" <?php if ($this->opts['categories']['show_style'] == "ul-nested") { 
							echo 'selected="selected" '; } ?>><?php _e('Ungeordnete Liste (ul) Verschachtelt', SITE_CATEGORIES_I18N_DOMAIN); ?></option>
						<option value="ol" <?php if ($this->opts['categories']['show_style'] == "ol") { 
							echo 'selected="selected" '; } ?>><?php _e('Geordnete Liste (ol)', SITE_CATEGORIES_I18N_DOMAIN); ?></option>
						<option value="ol-nested" <?php if ($this->opts['categories']['show_style'] == "ol-nested") { 
							echo 'selected="selected" '; } ?>><?php _e('Geordnete Liste (ol) Verschachtelt', SITE_CATEGORIES_I18N_DOMAIN); ?></option>
						<option value="accordion" <?php if ($this->opts['categories']['show_style'] == "accordion") { 
							echo 'selected="selected" '; } ?>><?php _e('Akkordeon', SITE_CATEGORIES_I18N_DOMAIN); ?></option>
						<option value="grid" <?php if ($this->opts['categories']['show_style'] == "grid") { 
							echo 'selected="selected" '; } ?>><?php _e('Raster', SITE_CATEGORIES_I18N_DOMAIN); ?></option>
						<option value="select-nested" <?php if ($this->opts['categories']['show_style'] == "select-nested") { 
							echo 'selected="selected" '; } ?>><?php _e('Dropdown (ausw??hlen) Verschachtelt', SITE_CATEGORIES_I18N_DOMAIN); ?></option>
						<option value="select-flat" <?php if ($this->opts['categories']['show_style'] == "select-flat") { 
							echo 'selected="selected" '; } ?>><?php _e('Dropdown (ausw??hlen)', SITE_CATEGORIES_I18N_DOMAIN); ?></option>
					</select>
				</div>
				<div style="<?php echo $display_grid_accordion_options; ?>" class="site-categories-parent-child-right site-categories-accordion-options site-categories-grid-options">
					<p><?php _e('Untergeordnete',  SITE_CATEGORIES_I18N_DOMAIN); ?></p>
					
					<select id="site-categories-show-style-children" name="bcat[categories][show_style_children]">
						<option value="ul" <?php if ($this->opts['categories']['show_style_children'] == "ul") { 
							echo 'selected="selected" '; } ?>><?php _e('Ungeordnete Liste (ul)', SITE_CATEGORIES_I18N_DOMAIN); ?></option>
						<option value="ul-nested" <?php if ($this->opts['categories']['show_style_children'] == "ul-nested") { 
							echo 'selected="selected" '; } ?>><?php _e('Ungeordnete Liste (ul) Verschachtelt', SITE_CATEGORIES_I18N_DOMAIN); ?></option>
						<option value="ol" <?php if ($this->opts['categories']['show_style_children'] == "ol") { 
							echo 'selected="selected" '; } ?>><?php _e('Geordnete Liste (ol)', SITE_CATEGORIES_I18N_DOMAIN); ?></option>
						<option value="ol-nested" <?php if ($this->opts['categories']['show_style_children'] == "ol-nested") { 
							echo 'selected="selected" '; } ?>><?php _e('Geordnete Liste (ol) Verschachtelt', SITE_CATEGORIES_I18N_DOMAIN); ?></option>
						<option value="select-nested" <?php if ($this->opts['categories']['show_style_children'] == "select-nested") { 
							echo 'selected="selected" '; } ?>><?php _e('Dropdown (ausw??hlen) Verschachtelt', SITE_CATEGORIES_I18N_DOMAIN); ?></option>
						<option value="select-flat" <?php if ($this->opts['categories']['show_style_children'] == "select-flat") { 
							echo 'selected="selected" '; } ?>><?php _e('Dropdown (ausw??hlen)', SITE_CATEGORIES_I18N_DOMAIN); ?></option>
					</select>
				</div>
			</td>
		</tr>

		<tr class="form-field site-categories-non-grid-options form-field site-categories-non-select-options" <?php if (($this->opts['categories']['show_style'] == "grid") || ($this->opts['categories']['show_style'] == "select-flat") || ($this->opts['categories']['show_style'] == "select-nested")) { echo ' style="display: none" '; } ?>>
			<th scope="row">
				<label for="site-categories-per-page"><?php _e('Kategorien pro Seite', SITE_CATEGORIES_I18N_DOMAIN); ?></label>
			</th>
			<td>
				<div class="site-categories-parent-child-left">
					<p class="site-categories-accordion-options site-categories-grid-options" style="<?php echo $display_grid_accordion_options; ?>"><?php _e('??bergeordnete',  SITE_CATEGORIES_I18N_DOMAIN); ?></p>
					<input type="text" id="site-categories-per-page" name="bcat[categories][per_page]" 
						value="<?php echo $this->opts['categories']['per_page']; ?>" />
				</div>
				<div style="<?php echo $display_grid_accordion_options; ?>" class="site-categories-parent-child-right site-categories-accordion-options site-categories-grid-options">
					<p><?php _e('Untergeordnete',  SITE_CATEGORIES_I18N_DOMAIN); ?></p>
					<?php _e('Alle Untergeordneten werden gezeigt', SITE_CATEGORIES_I18N_DOMAIN); ?>
				</div>
			</td>
		</tr>

		<tr class="form-field site-categories-grid-options" <?php if ($this->opts['categories']['show_style'] != "grid") { echo ' style="display: none" '; } ?>>
			<th scope="row">
				<label for="site-categories-per-page"><?php _e('Kategorien pro Seite', SITE_CATEGORIES_I18N_DOMAIN); ?></label>
			</th>
			<td>
				<p><?php _e('Rasteroptionen', SITE_CATEGORIES_I18N_DOMAIN); ?></p>
				<input type="text" class='' size="5" style="width: 50px" id="site-categories-show-style-grid-cols" name="bcat[categories][grid_cols]" 
					value="<?php echo intval($this->opts['categories']['grid_cols']); ?>" /> <label for="site-categories-show-style-grid-cols"><?php _e('Anzahl der Spalten', SITE_CATEGORIES_I18N_DOMAIN); ?></label><br />
				<input type="text" class='' size="5" style="width: 50px"  id="site-categories-show-style-grid-rows" name="bcat[categories][grid_rows]" 
						value="<?php echo intval($this->opts['categories']['grid_rows']); ?>" /> <label for="site-categories-show-style-grid-rows"><?php _e('Anzahl der Reihen', SITE_CATEGORIES_I18N_DOMAIN); ?></label><br />
			</td>
		</tr>

		<tr>
			<th scope="row">
				<label for="site-categories-orderby"><?php _e('Ordnen nach', SITE_CATEGORIES_I18N_DOMAIN); ?></label>
			</th>
			<td>
				<p><?php _e('Diese Reihenfolge nach Option steuert, wie die aufgelisteten Seiten-Kategorien auf der Listingseite sortiert werden.', 
					SITE_CATEGORIES_I18N_DOMAIN); ?></p>
				<div class="site-categories-parent-child-left">
					<p class="site-categories-accordion-options site-categories-grid-options" style="<?php echo $display_grid_accordion_options; ?>"><?php _e('??bergeordnete',  SITE_CATEGORIES_I18N_DOMAIN); ?></p>

					<select id="site-categories-orderby" name="bcat[categories][orderby]">
						<option value="name" <?php if ($this->opts['categories']['orderby'] == "name") { 
							echo 'selected="selected" '; } ?>><?php _e('Name', SITE_CATEGORIES_I18N_DOMAIN); ?></option>
						<option value="id" <?php if ($this->opts['categories']['orderby'] == "id") { 
							echo 'selected="selected" '; } ?>><?php _e('Kategorie ID', SITE_CATEGORIES_I18N_DOMAIN); ?></option>
						<option value="none" <?php if ($this->opts['categories']['orderby'] == "none") { 
							echo 'selected="selected" '; } ?>><?php _e('Nichts', SITE_CATEGORIES_I18N_DOMAIN); ?></option>
					</select>
					<select id="site-categories-order" name="bcat[categories][order]">
						<option value="ASC" <?php if ($this->opts['categories']['order'] == "ASC") { 
							echo 'selected="selected" '; } ?>><?php _e('ASC', SITE_CATEGORIES_I18N_DOMAIN); ?></option>
						<option value="DESC" <?php if ($this->opts['categories']['order'] == "DESC") { 
							echo 'selected="selected" '; } ?>><?php _e('DESC', SITE_CATEGORIES_I18N_DOMAIN); ?></option>
					</select>
				</div>
				<div style="<?php echo $display_grid_accordion_options; ?>" class="site-categories-parent-child-right site-categories-accordion-options site-categories-grid-options">
					<p><?php _e('Untergeordnete',  SITE_CATEGORIES_I18N_DOMAIN); ?></p>
					
					<select id="site-categories-orderby-children" name="bcat[categories][orderby_children]">
						<option value="name" <?php if ($this->opts['categories']['orderby_children'] == "name") { 
							echo 'selected="selected" '; } ?>><?php _e('Name', SITE_CATEGORIES_I18N_DOMAIN); ?></option>
						<option value="id" <?php if ($this->opts['categories']['orderby_children'] == "id") { 
							echo 'selected="selected" '; } ?>><?php _e('Kategorie ID', SITE_CATEGORIES_I18N_DOMAIN); ?></option>
						<option value="none" <?php if ($this->opts['categories']['orderby_children'] == "none") { 
							echo 'selected="selected" '; } ?>><?php _e('Nichts', SITE_CATEGORIES_I18N_DOMAIN); ?></option>
					</select>
					<select id="site-categories-order-children" name="bcat[categories][order_children]">
						<option value="ASC" <?php if ($this->opts['categories']['order_children'] == "ASC") { 
							echo 'selected="selected" '; } ?>><?php _e('ASC', SITE_CATEGORIES_I18N_DOMAIN); ?></option>
						<option value="DESC" <?php if ($this->opts['categories']['order_children'] == "DESC") { 
							echo 'selected="selected" '; } ?>><?php _e('DESC', SITE_CATEGORIES_I18N_DOMAIN); ?></option>
					</select>
				</div>
			</td>
		</tr>

		<tr>
			<th scope="row">
				<label for="site-categories-hide-empty"><?php _e('Leere Kategorien ausblenden', SITE_CATEGORIES_I18N_DOMAIN); ?></label>
			</th>
			<td>
				<div class="site-categories-parent-child-left">
					<p class="site-categories-accordion-options site-categories-grid-options" style="<?php echo $display_grid_accordion_options; ?>"><?php _e('??bergeordnete',  SITE_CATEGORIES_I18N_DOMAIN); ?></p>
				
					<input type="radio" name="bcat[categories][hide_empty]" id="category-hide-empty-yes" value="1" <?php if ($this->opts['categories']['hide_empty'] == "1") { echo ' checked="checked" '; }?> /> <label for="category-hide-empty-yes"><?php _e('Ja', SITE_CATEGORIES_I18N_DOMAIN) ?></label><br /><input type="radio" name="bcat[categories][hide_empty]" id="category-hide-empty-no" value="0" 
					<?php if ($this->opts['categories']['hide_empty'] == "0") { echo ' checked="checked" '; }?>/> <label for="category-hide-empty-no"><?php _e('Nein', SITE_CATEGORIES_I18N_DOMAIN); ?></label>
				</div>
				<div style="<?php echo $display_grid_accordion_options; ?>" class="site-categories-parent-child-right site-categories-accordion-options site-categories-grid-options">
			
					<p><?php _e('Untergeordnete',  SITE_CATEGORIES_I18N_DOMAIN); ?></p>
			
					<input type="radio" name="bcat[categories][hide_empty_children]" id="category-hide-empty-children-yes" value="1" <?php if ($this->opts['categories']['hide_empty_children'] == "1") { echo ' checked="checked" '; }?> /> <label for="category-hide-empty-children-yes"><?php _e('Ja', SITE_CATEGORIES_I18N_DOMAIN) ?></label><br /><input type="radio" name="bcat[categories][hide_empty_children]" id="category-hide-empty-children-no" value="0" <?php if ($this->opts['categories']['hide_empty_children'] == "0") { echo ' checked="checked" '; }?>/> <label for="category-hide-empty-children-no"><?php _e('Nein', SITE_CATEGORIES_I18N_DOMAIN); ?></label>
				</div>
			</td>
		</tr>

		<tr>
			<th scope="row">
				<label for="site-categories-show-counts"><?php _e('Z??hler anzeigen', SITE_CATEGORIES_I18N_DOMAIN); ?></label>
			</th>
			<td>
				<div class="site-categories-parent-child-left">

					<p class="site-categories-accordion-options site-categories-grid-options" style="<?php echo $display_grid_accordion_options; ?>"><?php _e('??bergeordnete',  SITE_CATEGORIES_I18N_DOMAIN); ?></p>
				
					<input type="radio" name="bcat[categories][show_counts]" id="category-show-counts-yes" value="1" 
					<?php if ($this->opts['categories']['show_counts'] == "1") { echo ' checked="checked" '; }?> /> <label 
					for="category-show-counts-yes"><?php _e('Ja', SITE_CATEGORIES_I18N_DOMAIN) ?></label><br />
					
					<input type="radio" name="bcat[categories][show_counts]" id="category-show-counts-no" value="0" 
					<?php if ($this->opts['categories']['show_counts'] == "0") { echo ' checked="checked" '; }?>/> <label 
					for="category-show-counts-no"><?php _e('Nein', SITE_CATEGORIES_I18N_DOMAIN); ?></label>
				</div>
				
				<div style="<?php echo $display_grid_accordion_options; ?>" 
						class="site-categories-parent-child-right site-categories-accordion-options site-categories-grid-options">
				
					<p><?php _e('Untergeordnete',  SITE_CATEGORIES_I18N_DOMAIN); ?></p>
				
					<input type="radio" name="bcat[categories][show_counts_children]" id="category-show-counts-children-yes" value="1" 
					<?php if ($this->opts['categories']['show_counts_children'] == "1") { echo ' checked="checked" '; }?> /> 
					<label for="category-show-counts-children-yes"><?php _e('Ja', SITE_CATEGORIES_I18N_DOMAIN) ?></label><br />
					
					<input type="radio" name="bcat[categories][show_counts_children]" id="category-show-counts-children-no" value="0" 
					<?php if ($this->opts['categories']['show_counts_children'] == "0") { echo ' checked="checked" '; }?>/> <label 
					for="category-show-counts-children-no"><?php _e('Nein', SITE_CATEGORIES_I18N_DOMAIN); ?></label>

				</div>
				
			</td>
		</tr>

		<tr class="site-categories-non-select-options" <?php if (($this->opts['categories']['show_style'] == "select-flat") || ($this->opts['categories']['show_style'] == "select-nested")) { echo ' style="display: none" '; } ?>>
			<th scope="row">
				<label for="site-categories-show-description"><?php _e('Kategoriebeschreibung anzeigen', SITE_CATEGORIES_I18N_DOMAIN); ?></label>
			</th>
			<td>
				<div class="site-categories-parent-child-left">

					<p class="site-categories-accordion-options site-categories-grid-options" style="<?php echo $display_grid_accordion_options; ?>"><?php _e('??bergeordnete',  SITE_CATEGORIES_I18N_DOMAIN); ?></p>

					<input type="radio" name="bcat[categories][show_description]" id="category-show-description-yes" value="1" 
					<?php if ($this->opts['categories']['show_description'] == "1") { echo ' checked="checked" '; }?> /> <label 
					for="category-show-description-yes"><?php _e('Ja', SITE_CATEGORIES_I18N_DOMAIN) ?></label><br />
				
					<input type="radio" name="bcat[categories][show_description]" id="category-show-description-no" value="0" <?php 
					if ($this->opts['categories']['show_description'] == "0") { echo ' checked="checked" '; }?>/> <label 
					for="category-show-description-no"><?php _e('Nein', SITE_CATEGORIES_I18N_DOMAIN); ?></label>

				</div>
				
				<div style="<?php echo $display_grid_accordion_options; ?>" 
					class="site-categories-parent-child-right site-categories-accordion-options site-categories-grid-options">

					<p><?php _e('Untergeordnete',  SITE_CATEGORIES_I18N_DOMAIN); ?></p>
					<input type="radio" name="bcat[categories][show_description_children]" id="category-show-description-children-yes" value="1" 
					<?php if ($this->opts['categories']['show_description_children'] == "1") { echo ' checked="checked" '; }?> /> <label 
					for="category-show-description-children-yes"><?php _e('Ja', SITE_CATEGORIES_I18N_DOMAIN) ?></label><br />
				
					<input type="radio" name="bcat[categories][show_description_children]" id="category-show-description-children-no" value="0" <?php 
					if ($this->opts['categories']['show_description_children'] == "0") { echo ' checked="checked" '; }?>/> <label 
					for="category-show-description-children-no"><?php _e('Nein', SITE_CATEGORIES_I18N_DOMAIN); ?></label>

				</div>

			</td>
		</tr>

		<tr class="site-categories-non-select-options" <?php if (($this->opts['categories']['show_style'] == "select-flat") || ($this->opts['categories']['show_style'] == "select-nested")) { echo ' style="display: none" '; } ?>>
			<th scope="row">
				<label for="site-categories-icons"><?php _e('Symbole anzeigen', SITE_CATEGORIES_I18N_DOMAIN); ?></label>
			</th>
			<td>
				<div class="site-categories-parent-child-left">

					<p class="site-categories-accordion-options site-categories-grid-options" style="<?php echo $display_grid_accordion_options; ?>"><?php _e('??bergeordnete',  SITE_CATEGORIES_I18N_DOMAIN); ?></p>

					<input type="radio" name="bcat[categories][icon_show]" id="category-icons-show-yes" value="1" 
					<?php if ($this->opts['categories']['icon_show'] == "1") { echo ' checked="checked" '; }?> /> <label 
					for="category-icons-show-yes"><?php _e('Ja', SITE_CATEGORIES_I18N_DOMAIN) ?></label><br />
					
					<input type="radio" name="bcat[categories][icon_show]" id="category-icons-show-no" value="0" 
					<?php if ($this->opts['categories']['icon_show'] == "0") { echo ' checked="checked" '; }?>/> <label 
					for="category-icons-show-no"><?php _e('Nein', SITE_CATEGORIES_I18N_DOMAIN); ?></label>

				</div>
				<div style="<?php echo $display_grid_accordion_options; ?>" 
					class="site-categories-parent-child-right site-categories-accordion-options site-categories-grid-options">

					<p><?php _e('Untergeordnete',  SITE_CATEGORIES_I18N_DOMAIN); ?></p>
				
					<input type="radio" name="bcat[categories][icon_show_children]" id="category-icons-show-children-yes" value="1" 
					<?php if ($this->opts['categories']['icon_show_children'] == "1") { echo ' checked="checked" '; }?> /> <label 
					for="category-icons-show-children-yes"><?php _e('Ja', SITE_CATEGORIES_I18N_DOMAIN) ?></label><br />
					
					<input type="radio" name="bcat[categories][icon_show_children]" id="category-icons-show-children-no" value="0" 
					<?php if ($this->opts['categories']['icon_show_children'] == "0") { echo ' checked="checked" '; }?>/> <label 
					for="category-icons-show-children-no"><?php _e('Nein', SITE_CATEGORIES_I18N_DOMAIN); ?></label>

				</div>
			</td>
		</tr>
		<tr class="form-field form-field site-categories-non-select-options" <?php if (($this->opts['categories']['show_style'] == "select-flat") || ($this->opts['categories']['show_style'] == "select-nested")) { echo ' style="display: none" '; } ?>>
			<th scope="row">
				<label for="site-categories-icons"><?php _e('Symbolgr????e', SITE_CATEGORIES_I18N_DOMAIN); ?></label>
			</th>
			<td>
				<div class="site-categories-parent-child-left">

					<p class="site-categories-accordion-options site-categories-grid-options" style="<?php echo $display_grid_accordion_options; ?>"><?php _e('??bergeordnete',  SITE_CATEGORIES_I18N_DOMAIN); ?></p>
					<input type="text" class='' size="5" name="bcat[categories][icon_size]" 
						value="<?php echo intval($this->opts['categories']['icon_size']); ?>" />px	<?php _e('Quadrat', SITE_CATEGORIES_I18N_DOMAIN); ?>
					<p class="description"><?php _e('Standard ist 32px', SITE_CATEGORIES_I18N_DOMAIN); ?></p>

				</div>
				<div style="<?php echo $display_grid_accordion_options; ?>" 
					class="site-categories-parent-child-right site-categories-accordion-options site-categories-grid-options">

					<p ><?php _e('Untergeordnete',  SITE_CATEGORIES_I18N_DOMAIN); ?></p>

					<input type="text" class='' size="5" name="bcat[categories][icon_size_children]" 
						value="<?php echo intval($this->opts['categories']['icon_size_children']); ?>" />px  <?php _e('square', SITE_CATEGORIES_I18N_DOMAIN); ?>
					<p class="description"><?php _e('Standard ist 32px', SITE_CATEGORIES_I18N_DOMAIN); ?></p>

				</div>
			</td>
		</tr>		

		<?php
			if ((isset($this->opts['categories']['default_icon_id'])) && (intval($this->opts['categories']['default_icon_id']))) {
				$bcat_image_id = intval($this->opts['categories']['default_icon_id']);
			} else {
				$bcat_image_id = 0;
			}
		?>
		<tr class="form-field form-field site-categories-non-select-options" <?php if (($this->opts['categories']['show_style'] == "select-flat") || ($this->opts['categories']['show_style'] == "select-nested")) { echo ' style="display: none" '; } ?>>
			<th scope="row" valign="top"><label for="upload_image"><?php _ex('Standard Kategoriebild', 'Kategoriebild', SITE_CATEGORIES_I18N_DOMAIN); ?></label></th>
			<td>
				<p class="description"><?php _e('Lade ein Bild hoch oder w??hle eines aus, das als Standardkategoriesymbole verwendet werden soll. Stelle sicher, dass es mindestens so gro?? ist wie die oben angegebene Symbolgr????e. Eine quadratische Version dieses Bildes wird automatisch generiert.', SITE_CATEGORIES_I18N_DOMAIN) ?></p>
				<input type="hidden" id="bcat_image_id" value="<?php echo $bcat_image_id; ?>" name="bcat[categories][default_icon_id]" />
				<input id="bcat_image_upload" class="button-secondary" type="button" value="<?php _e('Bild ausw??hlen', SITE_CATEGORIES_I18N_DOMAIN); ?>" <?php
					if ($bcat_image_id) { echo ' style="display: none;" '; }; ?> />
				<input id="bcat_image_remove" class="button-secondary" type="button" value="<?php _e('Entferne Bild', SITE_CATEGORIES_I18N_DOMAIN); ?>" <?php
					if (!$bcat_image_id) { echo ' style="display: none;" '; }; ?> />
				<br />
				<?php
					if ((isset($this->opts['categories']['default_icon_id'])) && (intval($this->opts['categories']['default_icon_id']))) {
						
						$bcat_image_default_src = '';
						$image_src	= wp_get_attachment_image_src(intval($this->opts['categories']['default_icon_id']), array(100, 100));
						if (!$image_src) {
							$image_src[0] = "#";
						}
					} else {
						$bcat_image_default_src = $this->get_default_category_icon_url();
						$image_src[0] = $bcat_image_default_src;
					}
					?>
					<img id="bcat_image_src" src="<?php echo $image_src[0]; ?>" alt="" style="margin-top: 10px; max-width: 300px; max-height: 300px" 
						rel="<?php echo $bcat_image_default_src; ?>"/>
					<?php
				?></p>
			</td>
		</tr>
		
		</table>
		<?php
	}
	
	/**
	 * 
	 *
	 * @since 1.0.0
	 *
	 * @param none
	 * @return none
	 */
	function settings_main_sites_display_options_panel() {
		?>
		<table class="form-table">

		<tr class="form-field" >
			<th scope="row">
				<label for="site-categories-sites-show-style"><?php _e('Anzeigestil', SITE_CATEGORIES_I18N_DOMAIN); ?></label>
			</th>
			<td>
				<select id="site-categories-sites-show-style" name="bcat[sites][show_style]">
					<option value="ul" <?php if ($this->opts['sites']['show_style'] == "ul") { 
						echo 'selected="selected" '; } ?>><?php _e('Ungeordnete Liste (ul)', SITE_CATEGORIES_I18N_DOMAIN); ?></option>
					<option value="ol" <?php if ($this->opts['sites']['show_style'] == "ol") { 
						echo 'selected="selected" '; } ?>><?php _e('Geordnete Liste (ol)', SITE_CATEGORIES_I18N_DOMAIN); ?></option>
				</select>
			</td>
		</tr>
				
		<tr class="form-field" >
			<th scope="row">
				<label for="site-categories-sites-header-prefix"><?php _e('Pr??fix vor dem Namen der Seiten-Kategorie', SITE_CATEGORIES_I18N_DOMAIN); ?></label>
			</th>
			<td>
				<input type="text" id="site-categories-sites-header-prefix" name="bcat[sites][header_prefix]" 
				value="<?php echo $this->opts['sites']['header_prefix']; ?>" />
			</td>
		</tr>

		<tr>
			<th scope="row">
				<label for="site-categories-sites-return-link"><?php _e('Zeige Zur??ck-Link', SITE_CATEGORIES_I18N_DOMAIN); ?></label>
			</th>
			<td>
				<p><?php _e('Der Zur??ck-Link wird in der Seiten-Liste angezeigt und ist eine R??ckkehr zur Haupt-Landingpage der Seiten-Kategorien. Keine R??ckkehr zur vorherigen Seite, die der Benutzer angezeigt hat.', SITE_CATEGORIES_I18N_DOMAIN); ?></p>
				<input type="radio" name="bcat[sites][return_link]" id="category-site-return_link-yes" value="1" 
				<?php if ($this->opts['sites']['return_link'] == "1") { echo ' checked="checked" '; }?> /> <label 
				for="category-site-return-link-yes"><?php _e('Ja', SITE_CATEGORIES_I18N_DOMAIN) ?></label><br />
				
				<input type="radio" name="bcat[sites][return_link]" id="category-site-return_link-no" value="0" 
				<?php if ($this->opts['sites']['return_link'] == "0") { echo ' checked="checked" '; }?>/> <label 
				for="category-site-return-link-no"><?php _e('Nein', SITE_CATEGORIES_I18N_DOMAIN); ?></label>
			</td>
		</tr>
		<tr class="form-field" >
			<th scope="row">
				<label for="site-categories-sites-return-link-label"><?php _e('Zeige Zur??ck-Link Etikett', SITE_CATEGORIES_I18N_DOMAIN); ?></label>
			</th>
			<td>
				<input type="text" id="site-categories-sites-return-link-label" name="bcat[sites][return_link_label]" 
				value="<?php echo $this->opts['sites']['return_link_label']; ?>" />
			</td>
		</tr>
		
		<tr class="form-field" >
			<th scope="row">
				<label for="site-categories-sites-per-page"><?php _e('Webseiten pro Seite', SITE_CATEGORIES_I18N_DOMAIN); ?></label>
			</th>
			<td>
				<input type="text" id="site-categories-sites-per-page" name="bcat[sites][per_page]" 
				value="<?php echo $this->opts['sites']['per_page']; ?>" />
			</td>
		</tr>

<?php  ?>
		<tr>
			<th scope="row">
				<label for="site-categories-site-orderby"><?php _e('Ordnen nach', SITE_CATEGORIES_I18N_DOMAIN); ?></label>
			</th>
			<td>
				<p><?php _e('Diese Reihenfolge nach Option steuert, wie die aufgelisteten Seiten-Kategorien auf der Listingseite sortiert werden.', 
					SITE_CATEGORIES_I18N_DOMAIN); ?></p>
				<select id="site-categories-site-orderby" name="bcat[sites][orderby]">
					<option value="name" <?php if ($this->opts['sites']['orderby'] == "name") { 
						echo 'selected="selected" '; } ?>><?php _e('Name', SITE_CATEGORIES_I18N_DOMAIN); ?></option>
					<option value="id" <?php if ($this->opts['sites']['orderby'] == "id") { 
						echo 'selected="selected" '; } ?>><?php _e('Seiten ID', SITE_CATEGORIES_I18N_DOMAIN); ?></option>
					<option value="registered" <?php if ($this->opts['sites']['orderby'] == "registered") { 
						echo 'selected="selected" '; } ?>><?php _e('Registrierungsdatum', SITE_CATEGORIES_I18N_DOMAIN); ?></option>
					<option value="last_updated" <?php if ($this->opts['sites']['orderby'] == "last_updated") { 
						echo 'selected="selected" '; } ?>><?php _e('Letzte Aktualisierung', SITE_CATEGORIES_I18N_DOMAIN); ?></option>
				</select>
				<select id="site-categories-site-order" name="bcat[sites][order]">
					<option value="ASC" <?php if ($this->opts['sites']['order'] == "ASC") { 
						echo 'selected="selected" '; } ?>><?php _e('ASC', SITE_CATEGORIES_I18N_DOMAIN); ?></option>
					<option value="DESC" <?php if ($this->opts['sites']['order'] == "DESC") { 
						echo 'selected="selected" '; } ?>><?php _e('DESC', SITE_CATEGORIES_I18N_DOMAIN); ?></option>
				</select>
				
			</td>
		</tr>
<?php  ?>

		<tr>
			<th scope="row">
				<label for="site-categories-site-show-description"><?php _e('Seiten-Beschreibung anzeigen', SITE_CATEGORIES_I18N_DOMAIN); ?></label>
			</th>
			<td>
				<input type="radio" name="bcat[sites][show_description]" id="category-site-show-description-yes" value="1" 
				<?php if ($this->opts['sites']['show_description'] == "1") { echo ' checked="checked" '; }?> /> <label 
				for="category-site-show-description-yes"><?php _e('Ja', SITE_CATEGORIES_I18N_DOMAIN) ?></label><br />
				
				<input type="radio" name="bcat[sites][show_description]" id="category-site-show-description-no" value="0" 
				<?php if ($this->opts['sites']['show_description'] == "0") { echo ' checked="checked" '; }?>/> <label 
				for="category-site-show-description-no"><?php _e('Nein', SITE_CATEGORIES_I18N_DOMAIN); ?></label>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="site-categories-sites-open-blank"><?php _e('??ffne Seiten-Links in einem neuen Fenster', SITE_CATEGORIES_I18N_DOMAIN); ?></label>
			</th>
			<td>
				<input type="radio" name="bcat[sites][open_blank]" id="category-site-open_blank-yes" value="1" 
				<?php if ($this->opts['sites']['open_blank'] == "1") { echo ' checked="checked" '; }?> /> <label 
				for="category-site-open_blank-yes"><?php _e('Ja', SITE_CATEGORIES_I18N_DOMAIN) ?></label><br />
				
				<input type="radio" name="bcat[sites][open_blank]" id="category-site-open_blank-no" value="0" 
				<?php if ($this->opts['sites']['open_blank'] == "0") { echo ' checked="checked" '; }?>/> <label 
				for="category-site-open_blank-no"><?php _e('Nein', SITE_CATEGORIES_I18N_DOMAIN); ?></label>
			</td>
		</tr>

		<tr>
			<th scope="row">
				<label for="site-categories-show-sites-icons"><?php _e('Symbole anzeigen', SITE_CATEGORIES_I18N_DOMAIN); ?></label>
			</th>
			<td>
				<?php
					if (function_exists('get_blog_avatar')) {
						?>
						<input type="radio" name="bcat[sites][icon_show]" id="site-categories-show-sites-icons-show-yes" value="1" 
						<?php if ($this->opts['sites']['icon_show'] == "1") { echo ' checked="checked" '; } ?>/> <label 
							for="site-categories-show-sites-icons-show-yes"><?php _e('Ja', SITE_CATEGORIES_I18N_DOMAIN) ?></label><br />
						
						<input type="radio" name="bcat[sites][icon_show]" id="site-categories-show-sites-icons-show-no" value="0" 
						<?php if ($this->opts['sites']['icon_show'] == "0") { echo ' checked="checked" '; } ?> /> <label 
							for="site-categories-show-sites-icons-show-no"><?php _e('Nein', SITE_CATEGORIES_I18N_DOMAIN); ?></label>						
						<?php
					} else {
						?><p><?php echo sprintf(__('Installiere das Plugin %1$sAvatare%2$s, um Seiten-Symbole anzuzeigen.', SITE_CATEGORIES_I18N_DOMAIN),	
							'<a href="https://n3rds.work/wiki/piestingtal-source-wiki/avatare-plugin/" target="_blank">', 
							'</a>'); ?></p><?php
					}
				?>

			</td>
		</tr>
		<?php if (function_exists('get_blog_avatar')) { ?>
		<tr>
			<th scope="row">
				<label for="site-categories-site-icon-size"><?php _e('Symbolgr????e', SITE_CATEGORIES_I18N_DOMAIN); ?></label>
			</th>
			<td>
				<input type="text" class='' size="5" id="site-categories-site-icon-size" name="bcat[sites][icon_size]" 
					value="<?php echo intval($this->opts['sites']['icon_size']); ?>" />px  <?php _e('Quadrat', SITE_CATEGORIES_I18N_DOMAIN); ?>
				<p class="description"><?php _e('Standard ist 32px', SITE_CATEGORIES_I18N_DOMAIN); ?></p>
			</td>
		</tr>		
		<?php } ?>
		</table>
		<?php
	}
	
	/**
	 * 
	 *
	 * @since 1.0.1
	 *
	 * @param none
	 * @return none
	 */
	function settings_main_sites_signup_form_options_panel() {

		//echo "opts<pre>"; print_r($this->opts); echo "</pre>";

		?>
		<p><?php _e('Mit diesen Optionen kannst Du die Informationen zu Seiten-Kategorien steuern, die im Front-End-Formular Neue Seite angezeigt werden.', SITE_CATEGORIES_I18N_DOMAIN); ?></p>
		<table class="form-table">

		<tr>
			<th scope="row">
				<label for="site-categories-signup-show"><?php _e('Abschnitt Seiten-Kategorien anzeigen', SITE_CATEGORIES_I18N_DOMAIN); ?></label>
			</th>
			<td>
				<input type="radio" name="bcat[sites][signup_show]" id="site-categories-signup-show-yes" value="1" 
				<?php if ($this->opts['sites']['signup_show'] == "1") { echo ' checked="checked" '; } ?> /> <label 
				for="site-categories-signup-show-yes"><?php _e('Ja', SITE_CATEGORIES_I18N_DOMAIN) ?></label><br />
				
				<input type="radio" name="bcat[sites][signup_show]" id="site-categories-signup-show-no" value="0" 
				<?php if ($this->opts['sites']['signup_show'] == "0") { echo ' checked="checked" '; } ?>/> <label 
				for="site-categories-signup-show-no"><?php _e('Nein', SITE_CATEGORIES_I18N_DOMAIN); ?></label>
				
			</td>
		</tr>

		<tr>
			<th scope="row">
				<label for="site-categories-signup-category-required"><?php _e('Auswahl der Seiten-Kategorien erforderlich', SITE_CATEGORIES_I18N_DOMAIN); ?></label>
			</th>
			<td>
				<input type="radio" name="bcat[sites][signup_category_required]" id="site-categories-signup-category-required-yes" value="1" 
				<?php if ($this->opts['sites']['signup_category_required'] == "1") { echo ' checked="checked" '; } ?> /> <label 
				for="site-categories-signup-category-required-yes"><?php _e('Ja', SITE_CATEGORIES_I18N_DOMAIN) ?></label><br />
				
				<input type="radio" name="bcat[sites][signup_category_required]" id="site-categories-signup-category-required-no" value="0" 
				<?php if ($this->opts['sites']['signup_category_required'] == "0") { echo ' checked="checked" '; } ?>/> <label 
				for="site-categories-signup-category-required-no"><?php _e('Nein', SITE_CATEGORIES_I18N_DOMAIN); ?></label>
				
			</td>
		</tr>

		<tr>
			<th scope="row">
				<label for="site-categories-signup-category-label"><?php _e('Beschriftung f??r Dropdown-Listen f??r Seiten-Kategorien', SITE_CATEGORIES_I18N_DOMAIN); ?></label>
			</th>
			<td>
				<input type="text" class='widefat' id="site-categories-signup-category-label" name="bcat[sites][signup_category_label]" 
					value="<?php echo stripslashes($this->opts['sites']['signup_category_label']); ?>" />
					<p class="description"><?php _e("Die Beschriftung wird ??ber der Anzahl der Kategorie-Dropdowns angezeigt", SITE_CATEGORIES_I18N_DOMAIN); ?></p>					
			</td>
		</tr>

		<tr>
			<th scope="row">
				<label for="site-categories-signup-description-required"><?php _e('Beschreibung ist erforderlich', SITE_CATEGORIES_I18N_DOMAIN); ?></label>
			</th>
			<td>
				<input type="radio" name="bcat[sites][signup_description_required]" id="site-categories-signup-description-required-yes" value="1" 
				<?php if ($this->opts['sites']['signup_description_required'] == "1") { echo ' checked="checked" '; }?> /> <label
				 for="site-categories-signup-description-required-yes"><?php _e('Ja', SITE_CATEGORIES_I18N_DOMAIN) ?></label><br />
				
				<input type="radio" name="bcat[sites][signup_description_required]" id="site-categories-signup-description-required-no" value="0" 
				<?php if ($this->opts['sites']['signup_description_required'] == "0") { echo ' checked="checked" '; }?>/> <label
				 for="site-categories-signup-description-required-no"><?php _e('Nein', SITE_CATEGORIES_I18N_DOMAIN); ?></label>
				
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="site-categories-signup-description-label"><?php _e('Beschriftung f??r Seiten-Kategorien Beschreibung', SITE_CATEGORIES_I18N_DOMAIN); ?></label>
			</th>
			<td>
				<input type="text" class='widefat' id="site-categories-signup-description-label" name="bcat[sites][signup_description_label]" 
					value="<?php echo stripslashes($this->opts['sites']['signup_description_label']); ?>" />
					<p class="description"><?php _e("Das Etikett wird ??ber der Seiten-Beschreibung angezeigt", SITE_CATEGORIES_I18N_DOMAIN); ?></p>					
			</td>
		</tr>

		<tr>
			<th scope="row">
				<label for="site-categories-orderby"><?php _e('Ordnen nach', SITE_CATEGORIES_I18N_DOMAIN); ?></label>
			</th>
			<td>
				<p><?php _e('Diese Reihenfolge nach Option steuert, wie die aufgelisteten Seiten-Kategorien in den Dropdown-Listen auf der Anmeldeseite sortiert werden.', 
					SITE_CATEGORIES_I18N_DOMAIN); ?></p>
				<div class="site-categories-parent-child-left">

					<select id="signups-site-categories-orderby" name="bcat[signups][orderby]">
						<option value="name" <?php if ($this->opts['signups']['orderby'] == "name") { 
							echo 'selected="selected" '; } ?>><?php _e('Name', SITE_CATEGORIES_I18N_DOMAIN); ?></option>
						<option value="id" <?php if ($this->opts['signups']['orderby'] == "id") { 
							echo 'selected="selected" '; } ?>><?php _e('Kategorie ID', SITE_CATEGORIES_I18N_DOMAIN); ?></option>
						<option value="none" <?php if ($this->opts['signups']['orderby'] == "none") { 
							echo 'selected="selected" '; } ?>><?php _e('Nichts', SITE_CATEGORIES_I18N_DOMAIN); ?></option>
					</select>
					<select id="signups-site-categories-order" name="bcat[signups][order]">
						<option value="ASC" <?php if ($this->opts['signups']['order'] == "ASC") { 
							echo 'selected="selected" '; } ?>><?php _e('ASC', SITE_CATEGORIES_I18N_DOMAIN); ?></option>
						<option value="DESC" <?php if ($this->opts['signups']['order'] == "DESC") { 
							echo 'selected="selected" '; } ?>><?php _e('DESC', SITE_CATEGORIES_I18N_DOMAIN); ?></option>
					</select>
				</div>
				
			</td>
		</tr>

		</table>
		<?php
	}
	
	/**
	 * 
	 *
	 * @since 1.0.0
	 *
	 * @param none
	 * @return none
	 */
	function settings_main_admin_display_options_panel() {
		?>
		<table class="form-table">
		<tr class="form-field" >
			<th scope="row">
				<label for="site-categories-landing-page"><?php _e('Landeseite ausw??hlen', SITE_CATEGORIES_I18N_DOMAIN); ?></label>
			</th>
			<td>
				<?php
					if (isset($this->opts['landing_page_id'])) {
						$landing_page_id = intval($this->opts['landing_page_id']);
					} else {
						$landing_page_id = 0;
					}
				?>
				<input type="hidden" name="bcat[landing_page_id_org]" id="landing_page_id_org" value="<?php echo $landing_page_id ?>" />
				<?php	

					wp_dropdown_pages( array( 
							'name'				=> 'bcat[landing_page_id]', 
							'id'				=> 'site-categories-landing-page',
							'echo'				=> 1, 
							'show_option_none'	=> __( '&mdash; W??hlen &mdash;' ), 
							'option_none_value' => '0', 
							'selected'			=>	$landing_page_id
						)
					);

					if ($this->opts['landing_page_id']) {
						?><p class="description"><?php _e('Die Liste der Seiten-Kategorien wird an die ausgew??hlte Seite angeh??ngt:', 
							SITE_CATEGORIES_I18N_DOMAIN); ?> <a href="<?php echo get_permalink($this->opts['landing_page_id']); ?>" 
								target="blank"><?php _e('Liste anzeigen', SITE_CATEGORIES_I18N_DOMAIN); ?></a></p><?php 
					}
				?>
			</td>
		</tr>
		<tr class="form-field" >
			<th scope="row">
				<label for="site-categories-use_rewrite"><?php _e('Pretty URLs f??r Kategorien', SITE_CATEGORIES_I18N_DOMAIN); ?></label>
			</th>
			<td>
				<select id="site-categories-use_rewrite" name="bcat[landing_page_use_rewrite]">
					<option value="yes" <?php if ($this->opts['landing_page_use_rewrite'] == "yes") { 
						echo 'selected="selected" '; } ?>><?php _e('Ja', SITE_CATEGORIES_I18N_DOMAIN); ?></option>
					<option value="no" <?php if ($this->opts['landing_page_use_rewrite'] == "no") { 
						echo 'selected="selected" '; } ?>><?php _e('Nein', SITE_CATEGORIES_I18N_DOMAIN); ?></option>
				</select>
				<p class="description"><?php _e('Verwende h??bsche URLs, wenn Du Webseiten aus einer ausgew??hlten Kategorie anzeigst. Wenn die Zielseite auf die Startseite eingestellt ist, werden h??bsche URLs automatisch auf Nein gesetzt', SITE_CATEGORIES_I18N_DOMAIN); ?></p>
			</td>
		</tr>
		</table>
		<?php		
	}
	
	/**
	 * 
	 *
	 * @since 1.0.2
	 *
	 * @param none
	 * @return none
	 */
	function settings_main_admin_display_selection_options_panel() {
		?>
		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="site-categories-signup-category-minimum"><?php _e('Minimale Seiten-Kategorien', SITE_CATEGORIES_I18N_DOMAIN); ?></label>
				</th>
				<td>
					<input type="text" class='widefat' id="site-categories-signup-category-minimum" name="bcat[sites][signup_category_minimum]" 
						value="<?php echo intval($this->opts['sites']['signup_category_minimum']); ?>" />
						<p class="description"><?php _e("Die Mindestanzahl der einzustellenden Seiten-Kategorien. Kann f??r kein Minimum leer sein. Dieser Wert sollte kleiner sein als der Wert der unten angegebenen 'Maximalen Site-Kategorien'.", SITE_CATEGORIES_I18N_DOMAIN); ?></p>

				</td>
			</tr>
		<tr>
			<th scope="row">
				<label for="site-categories-sites-category-limit"><?php _e('Maximale Seiten-Kategorien', SITE_CATEGORIES_I18N_DOMAIN); ?></label>
			</th>
			<td>
				<p class="description"><?php _e('Mit dieser Option kannst Du die Anzahl der f??r die Seite verf??gbaren Seiten-Kategorien begrenzen. Diese Option f??gt eine Reihe von Dropdown-Formularelementen auf der Seite "Allgemeine Einstellungen" hinzu, auf denen der Administrator die Kategorien f??r seine Seite festlegen kann.', SITE_CATEGORIES_I18N_DOMAIN); ?></p>
				<input type="text" class='widefat' id="site-categories-sites-category-limit" name="bcat[sites][category_limit]" 
					value="<?php echo intval($this->opts['sites']['category_limit']); ?>" />
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="site-categories-sites-category-limit-prosites"><?php _e('Blog-Hosting', SITE_CATEGORIES_I18N_DOMAIN); ?></label>
			</th>
			<td>		
				<?php
					if (function_exists('is_pro_user')) {
				
						// If not a Pro User (whatever that is). Then we do not show the Pro Site section
						if (is_pro_user(get_current_user_id())) {

							$levels = (array)get_site_option('psts_levels');
							if ($levels) {
								?>
								<p class="description"><?php _e('Du kannst Deinen Blog-Hosting-Level mehr Auswahlm??glichkeiten f??r Seiten-Kategorien anbieten',
								 SITE_CATEGORIES_I18N_DOMAIN); ?></p>
								<ul style="float: left; width: 100%;">
								<?php
									$level_value = '';
									foreach($levels as $level_idx => $level) {
										if (isset($this->opts['sites']['prosites_category_limit'][$level_idx])) {
											$level_value = intval($this->opts['sites']['prosites_category_limit'][$level_idx]);
										} 
								
										if ($level_value == 0)
											$level_value = '';
								
										?><li><input type="text" id="bcat-sites-prosites-category-limit-<?php echo $level_idx; ?>" width="40%" 
											value="<?php echo $level_value; ?>" 
											name="bcat[sites][prosites_category_limit][<?php echo $level_idx; ?>]" /> <label for="bcat-sites-prosites-category-limit-<?php echo $level_idx; ?>"><?php echo $level['name'] ?></label></li><?php

									}
								?>
								</ul>
								<?php
							}
						}
					} else {
						?><p class=""><?php echo sprintf(__('Wenn Du das Plugin %1$sBlog-Hosting%2$s installierst, kannst Du Deinen Blog-Hosting-Levels mehr Kategorien zur Auswahl anbieten.', SITE_CATEGORIES_I18N_DOMAIN),
						'<a href="https://n3rds.work/shop/artikel/ps-bloghosting-plugin/" target="_blank">', '</a>'); ?></p><?php
					}
				?>
			</td>
		</tr>

		<?php
			if ((isset($this->opts['sites']['category_excludes'])) 
			 && (is_array($this->opts['sites']['category_excludes'])) 
			 && (count($this->opts['sites']['category_excludes']))) {
				$cat_excludes = implode(',', $this->opts['sites']['category_excludes']);
			} else {
				$cat_excludes = '';
			}
		?>
		<tr>
			<th scope="row">
				<label for="site-categories-sites-category-exclude"><?php _e('Ausgenommene Kategorien', SITE_CATEGORIES_I18N_DOMAIN); ?></label>
			</th>		
			<td>
				<p class="description"><?php _e('Gib eine durch Kommas getrennte Liste von Kategorie-IDs ein. Diese Kategorien werden von der Dropdown-Auswahl auf der Neue Seite-Anmeldeseite sowie auf der Seite Blog-Einstellungen> Seiten-Kategorien ausgeschlossen.', SITE_CATEGORIES_I18N_DOMAIN); ?></p>
				<input type="text" class='widefat' id="site-categories-sites-category-excludes" name="bcat[sites][category_excludes]" 
					value="<?php echo $cat_excludes; ?>" />
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="site-categories-sites-category-default"><?php _e('Standardkategorie', SITE_CATEGORIES_I18N_DOMAIN); ?></label>
			</th>		
			<td>
				<p class="description"><?php _e('Lege die Standardkategorie fest, wenn der Seiten-Administrator keine Seiten-Kategorie ausw??hlt.', SITE_CATEGORIES_I18N_DOMAIN); ?></p>
				<?php
					$bcat_args = array(
						'taxonomy'			=>	SITE_CATEGORIES_TAXONOMY,
						'hierarchical'		=>	true,
						'hide_empty'		=>	false,
						'exclude'			=>	$cat_excludes,
						'show_count'		=>	1,
						'show_option_none'	=>	__('Keine ausgew??hlt', SITE_CATEGORIES_I18N_DOMAIN), 
						'name'				=>	'bcat[sites][category_default]',
						'class'				=>	'bcat_category',
					);
					if (isset($this->opts['sites']['category_default'])) {
						$bcat_args['selected'] = intval($this->opts['sites']['category_default']);
					}
					wp_dropdown_categories( $bcat_args ); 
					?> 
			</td>
		</tr>
		
		<tr>
			<th scope="row">
				<label for="site-categories-signup-category-parent-selectable-yes"><?php _e('Kategorie Eltern w??hlbar', SITE_CATEGORIES_I18N_DOMAIN); ?></label>
			</th>
			<td>
				<p><?php _e('Bei der Anzeige von Seiten-Kategorien auf der Zielseite mit den Stilen <strong>Raster</strong> oder <strong>Akkordeon</strong>. Es ist ratsam, diese Option auf <strong>Nein</strong> zu setzen. Diese Option steuert die Dropdown-Kategorienoptionen im Formular "Neue Seite" sowie die Seite "Einstellungen" in wp-admin.'); ?></p>
				<input type="radio" name="bcat[sites][signup_category_parent_selectable]" id="site-categories-signup-category-parent-selectable-yes" value="1" 
				<?php if ($this->opts['sites']['signup_category_parent_selectable'] == "1") { echo ' checked="checked" '; }?> /> <label
				 for="site-categories-signup-category-parent-selectable-yes"><?php _e('Ja', SITE_CATEGORIES_I18N_DOMAIN) ?></label><br />
				
				<input type="radio" name="bcat[sites][signup_category_parent_selectable]" id="site-categories-signup-category-parent-selectable-no" value="0" 
				<?php if ($this->opts['sites']['signup_category_parent_selectable'] == "0") { echo ' checked="checked" '; }?>/> <label
				 for="site-categories-signup-category-parent-selectable-no"><?php _e('Nein', SITE_CATEGORIES_I18N_DOMAIN); ?></label>
				
			</td>
		</tr>
		
		</table>
		<?php		
	}

	
	/**
	 * 
	 *
	 * @since 1.0.0
	 *
	 * @param none
	 * @return none
	 */
	function settings_site_select_categories_panel() {
		
		global $wpdb, $psts, $current_site, $current_blog;
		
		if (function_exists('is_pro_user')) {
			$site_level = $psts->get_level($wpdb->blogid);
			$levels = (array)get_site_option('psts_levels');			
			
			if (($levels) && (isset($levels[$site_level]))
			 && (isset($this->opts['sites']['prosites_category_limit'][$site_level]))) {
				$blog_category_limit = intval($this->opts['sites']['prosites_category_limit'][$site_level]);
				?><p><?php _e("Bloghosting Level:", SITE_CATEGORIES_I18N_DOMAIN); ?> <?php echo $levels[$site_level]['name']; ?></p><?php
			} else {
				if (isset($this->opts['sites']['category_limit']))
					$blog_category_limit = intval($this->opts['sites']['category_limit']);
				else
					$blog_category_limit = 1;				
			}

		} else {
			if (isset($this->opts['sites']['category_limit']))
				$blog_category_limit = intval($this->opts['sites']['category_limit']);
			else
				$blog_category_limit = 1;
		}

		if (($blog_category_limit > 100)	|| ($blog_category_limit < 1))
			$blog_category_limit = 1;

		//$current_site = $wpdb->blogid;
		//echo "current_site<pre>"; print_r($current_site); echo "</pre>";
		//echo "current_blog<pre>"; print_r($current_blog); echo "</pre>";
		
		//echo "wpdb->prefix=[". $wpdb->prefix ."]<br />";
		
		switch_to_blog( $current_site->blog_id );
		
		$site_categories = wp_get_object_terms($current_blog->blog_id, SITE_CATEGORIES_TAXONOMY);
		$cat_excludes = '';

		if ((is_multisite()) && (!is_super_admin())) {
			if ((isset($this->opts['sites']['category_excludes'])) 
			 && (is_Array($this->opts['sites']['category_excludes']))
			 && (count($this->opts['sites']['category_excludes']))) {
				$cat_excludes = implode(',', $this->opts['sites']['category_excludes']);
			} else {
				$this->opts['sites']['category_excludes'] = array();
			}
		}
		
		$cat_counter = 0;
		?><ol><?php
		while(true) {
			
			if (isset($site_categories[$cat_counter])) {
				$cat_selected = $site_categories[$cat_counter]->term_id;
			} else {
				$cat_selected = -1;
			}
									
			?><li><?php 
				$cat_ecluded = false;
				if ((is_multisite()) && (!is_super_admin())) {
					if (array_search($cat_selected, $this->opts['sites']['category_excludes']) !== false) {
						echo $site_categories[$cat_counter]->name ." - Verwaltet von Super Admin";
						$cat_ecluded = true;
						?><input type="hidden" name="bcat_site_categories[<?php echo $cat_counter; ?>]" 
							value="<?php echo $site_categories[$cat_counter]->term_id; ?>" /><?php
					}
				} 
				if ($cat_ecluded == false) {
					$bcat_args = array(
						'taxonomy'			=>	SITE_CATEGORIES_TAXONOMY,
						'hierarchical'		=>	true,
						'hide_empty'		=>	false,
						'exclude'			=>	$cat_excludes,
						'show_option_none'	=>	__('Keine ausgew??hlt', SITE_CATEGORIES_I18N_DOMAIN), 
						'name'				=>	'bcat_site_categories['. $cat_counter .']',
						'class'				=>	'bcat_category',
						'selected'			=>	$cat_selected,
						'orderby'			=>	'name',
						'order'				=>	'ASC'
					);
			
					if ($this->opts['sites']['signup_category_parent_selectable'] == 1)
						wp_dropdown_categories( $bcat_args );
					else
						$this->wp_dropdown_categories( $bcat_args );
				}
			?></li><?php
		
			$cat_counter += 1;
			if ($cat_counter >= $blog_category_limit) 
				break;
		}			
		?></ol><?php
		
		restore_current_blog();
	}
	
	/**
	 * 
	 *
	 * @since 1.0.0
	 *
	 * @param none
	 * @return none
	 */
	function settings_site_description_panel() {
		
		$bact_site_description = get_option('bact_site_description');

		?>
		<label for="bcat_site_description"><?php _e('Gib eine Seiten-Beschreibung ein, die auf der Landing-Seite verwendet werden soll.', SITE_CATEGORIES_I18N_DOMAIN); ?></label><br />
		<textarea name="bcat_site_description" style="width:100%;" cols="30" rows="10" id="bcat_site_description"><?php 
			echo stripslashes($bact_site_description); ?></textarea>
		<?php
	}
	
	/**
	 * 
	 *
	 * @since 1.0.0
	 *
	 * @param none
	 * @return none
	 */
	function process_categories_body ($content) {

		global $post;
		
		//echo "content=[". $content ."]<br />";
		if (is_admin()) return $content;
		if (!is_multisite()) return $content;
		if (!in_the_loop()) return $content;
		
		$this->load_config();
		$data = array();
		
		// We get the bcat options. This 'should' contain the variable 'landing_page_id' is the admin properly set things up
		if ((!isset($this->opts['landing_page_id'])) || (!intval($this->opts['landing_page_id'])))
			$opts['landing_page_id'] = 0; 
				
		if ($post->ID != intval($this->opts['landing_page_id'])) return $content;
		
		// Remove our own filter. Since we are here we should not need it. Plus in case other process call the content filters. 
		//remove_filter('the_content', array($this, 'process_categories_body'), 99);
		
		$category = '';
		$start_at = 1;
		
		if ($this->opts['landing_page_use_rewrite'] == "yes") {
			$category = get_query_var('category_name');
			$start_at = get_query_var('start_at');
		} else {
			if (isset($_GET['category'])) {
				$category = esc_attr($_GET['category']);
				//echo "category<pre>"; print_r($category); echo "</pre>";
			}
			if (isset($_GET['start_at'])) {
				$data['current_page'] = intval($_GET['start_at']);
			}				
		}
		
		// With this filter the user is checked to allow access. If the user DOES have access the return should be blank. However if 
		// the user DOES NOT have access then return content should be what to show the user in place of the Site Categories listing.
		// Alternately the admin can setup the filter to redirect the user to some other page. 
		$user_access_content = apply_filters('site_categories_user_can_view', '', 'landing');
		
		// If the filters returned simply false we return the default content'
		if ($user_access_content === false)
			return $content;

		// If the filters returned a string/text we want to use that as the user viewed content		
		if ((is_string($user_access_content)) && (!empty($user_access_content)))
			return $user_access_content;
				
		if ($category) {
			$data['term'] = get_term_by('slug', $category, SITE_CATEGORIES_TAXONOMY);
			if (( is_wp_error( $data['term'] ) ) || (!$data['term'])) {

				// Here is some fuzzy logic. The query_var 'category_name' is the first item off the page slug as in /page-slug/category-name/page-number/
				// So we need to check if it is a real intval (3, 6, 12, etc.) then we assume we don't have a category and we are viewing the top-level page
				// list of blog categories. IF we do have a valid category-name then the next query_var is the page-number

				$category_int = intval($category);
				if (($category == $category_int) && ($category_int != 0)) {
					$data['current_page'] = $category_int;
					$category = '';
				} else {

					$data['current_page']  = get_query_var('page');
					if (!$data['current_page']) {
						$data['current_page'] = get_query_var('start_at');
					}
				}
			} else {
				$data['current_page'] = get_query_var('page');

				if (!$data['current_page']) {
					$data['current_page'] = get_query_var('start_at');
				}
			}
		}

		if ((!isset($data['current_page'])) || ($data['current_page'] == 0))
			$data['current_page'] = 1;
		
		if ($category && $data['term']) {

			$args = $this->opts['sites'];

			$data['category']	= $category;

			$sites = $this->get_taxonomy_sites($data['term']->term_id);
			//echo "sites<pre>"; print_r($sites); echo "</pre>";
			if (count($sites) < $args['per_page']) {
				$data['sites'] = $sites;

			} else {

				$data['offset']			= intval($args['per_page']) * (intval($data['current_page'])-1); 
				$data['sites']			= array_slice($sites, $data['offset'], $args['per_page'], true);
				$data['total_pages']	= ceil(count($sites)/intval($args['per_page']));
												
				if (intval($data['current_page']) > 1) {

					$data['prev'] = array();
					$data['prev']['page_number'] = intval($data['current_page']) - 1;

					if ((isset($this->opts['landing_page_rewrite'])) && ($this->opts['landing_page_rewrite'] == true) && ($this->opts['landing_page_use_rewrite'] == "yes")) {

						$data['prev']['link_url'] = trailingslashit($this->opts['landing_page_slug']) . $data['term']->slug 
							. '/' . $data['prev']['page_number'];

					} else {

						//$data['prev']['link_url'] = $this->opts['landing_page_slug'] . '&amp;category_name='. $data['term']->slug 
						//	.'&amp;start_at=' . $data['prev']['page_number'];			
						$data['prev']['link_url'] = add_query_arg(array('category' => $data['term']->slug, 'start_at' => $data['prev']['page_number']), $this->opts['landing_page_slug']); 
					}

					$data['prev']['link_label'] = __('Vorherige Seite', SITE_CATEGORIES_I18N_DOMAIN);
				}
				
				if ($data['current_page'] < $data['total_pages']) {

					$data['next'] = array();
					
					$data['next']['page_number'] = $data['current_page'] + 1;
					
					if ((isset($this->opts['landing_page_rewrite'])) && ($this->opts['landing_page_rewrite'] == true) && ($this->opts['landing_page_use_rewrite'] == "yes")) {

						$data['next']['link_url'] = trailingslashit($this->opts['landing_page_slug']) . $data['term']->slug 
							. '/' . $data['next']['page_number'];

					} else {

						//$data['next']['link_url'] = $this->opts['landing_page_slug'] .'&amp;category_name='. $data['term']->slug 
						//	.'&amp;start_at=' . $data['next']['page_number'];			
						$data['next']['link_url'] = add_query_arg(array('category' => $data['term']->slug, 'start_at' => $data['next']['page_number']), $this->opts['landing_page_slug']); 

					}

					$data['next']['link_label'] = __('N??chste Seite', SITE_CATEGORIES_I18N_DOMAIN);
				}
			}
			
			if (!function_exists('get_blog_avatar')) {
				$args['icon_show'] = false;
			} else {
				$default_icon_src = $this->get_default_category_icon_url();
			}

			if (count($data['sites'])) {

				foreach($data['sites'] as $idx => $site) {

					$data['sites'][$idx]->bact_site_description = get_blog_option($site->blog_id, 'bact_site_description');

					if ((isset($args['icon_show'])) && ($args['icon_show'] == true)) {
						$icon_image_src = get_blog_avatar($site->blog_id, $args['icon_size']);
						if ((!$icon_image_src) || (!strlen($icon_image_src))) {
							$data['sites'][$idx]->icon_image_src = $default_icon_src;
						} else {
							$data['sites'][$idx]->icon_image_src = $icon_image_src;
						}
					}
				}
			}
			
			if ($this->opts['sites']['return_link']) {
				$content .= '<p><a href="'. esc_url($this->opts['landing_page_slug']) .'">'. $this->opts['sites']['return_link_label'] .'</p>';
			}
			
			$categories_string = apply_filters('site_categories_landing_list_sites_display', $content, $data, $args);
			return $categories_string;
				
		} else {
			$args = $this->opts['categories'];
			
			$get_terms_args = array();
			
			// For processing default category logic we need to include empty categories in our initial query. Then remove when we are done before the display.
			$get_terms_args['hide_empty']	=	$args['hide_empty'];
			//$get_terms_args['hide_empty']	=	0;

			$get_terms_args['orderby']		=	$args['orderby'];
			$get_terms_args['order']		=	$args['order'];
			$get_terms_args['pad_counts']	=	false;
			
			$get_terms_args['hierarchical']	=	false;
			
			if ($args['show_style'] == "grid") {
				$get_terms_args['pad_counts']		= 1;
				$get_terms_args['parent']			= 0;
				$get_terms_args['hierarchical']		= 0;

				// For the grid we replace the 'per_page' value with the number of rows * cols
				if (!isset($args['grid_cols'])) 
					$args['grid_cols'] = 2;

				if (!isset($args['grid_rows'])) 
					$args['grid_rows'] = 3;
				
				$args['per_page'] = intval($args['grid_rows']) * intval($args['grid_cols']);
			} else if ($args['show_style'] == "accordion") {
				$get_terms_args['pad_counts'] = 1;
				$get_terms_args['parent'] = 0;
				$get_terms_args['hierarchical']	= 0;
			} else if (($args['show_style'] == "select-flat") || ($args['show_style'] == "select-nested")) {
				$get_terms_args['per_page'] = -1;
				//$get_terms_args['per_page'] = -1;
			}
			//echo "args<pre>"; print_r($args); echo "</pre>";
			//echo "get_terms_args<pre>"; print_r($get_terms_args); echo "</pre>";
			
			//$unassigned_sites = $this->get_unassigned_sites();
			//echo "unassigned_sites<pre>"; print_r($unassigned_sites); echo "</pre>";
			
			$categories = get_terms( SITE_CATEGORIES_TAXONOMY, $get_terms_args );
			//echo "categories<pre>"; print_r($categories); echo "</pre>";
			
			if (($categories) && (count($categories))) {

				if (($args['show_style'] == 'select-flat') || ($args['show_style'] == 'select-nested')) {
					$data['categories'] = $categories;
				} else {
					if (/* ($args['per_page'] > 0) || */ (count($categories) < $args['per_page'])) {

						$data['categories'] = $categories;

					} else {

						$data['offset']			= intval($args['per_page']) * (intval($data['current_page'])-1); 
						$data['categories']		= array_slice($categories, $data['offset'], $args['per_page'], true);

						$data['total_pages']	= ceil(count($categories)/intval($args['per_page']));

						if (intval($data['current_page']) > 1) {

							$data['prev'] = array();
							$data['prev']['page_number'] = intval($data['current_page']) - 1;

							if ($data['prev']['page_number'] > 1) {
								if ((isset($this->opts['landing_page_rewrite'])) && ($this->opts['landing_page_rewrite'] == true) && ($this->opts['landing_page_use_rewrite'] == "yes")) {
									$data['prev']['link_url'] = trailingslashit($this->opts['landing_page_slug']) . $data['prev']['page_number'];
								} else {
									$data['prev']['link_url'] = add_query_arg(array('start_at' => $data['prev']['page_number']), $this->opts['landing_page_slug']); 
								}
							} else {
								$data['prev']['link_url'] = $this->opts['landing_page_slug'];
							}
							$data['prev']['link_label'] = __('Vorherige Seite', SITE_CATEGORIES_I18N_DOMAIN);						
						}
					
						if ($data['current_page'] < $data['total_pages']) {

							$data['next'] = array();

							$data['next']['page_number'] = $data['current_page'] + 1;

							if ((isset($this->opts['landing_page_rewrite'])) && ($this->opts['landing_page_rewrite'] == true) && ($this->opts['landing_page_use_rewrite'] == "yes")) {
								$data['next']['link_url'] = trailingslashit($this->opts['landing_page_slug']) . $data['next']['page_number'];
							} else {
								//$data['next']['link_url'] = $this->opts['landing_page_slug'] .'&amp;start_at=' . $data['next']['page_number'];
								$data['next']['link_url'] = add_query_arg(array('start_at' => $data['next']['page_number']), $this->opts['landing_page_slug']);
							}
							$data['next']['link_label'] = __('Next page', SITE_CATEGORIES_I18N_DOMAIN);
						}
					}
				}
				if (count($data['categories'])) {
					//echo "data<pre>"; print_r($data); echo "</pre>";

					$unassigned_sites = $this->get_unassigned_sites();
					//echo "unassigned_sites<pre>"; print_r($unassigned_sites); echo "</pre>";

					foreach($data['categories'] as $idx => $data_category) {

						if ((isset($args['icon_show'])) && ($args['icon_show'] == true)) {
							$data['categories'][$idx]->icon_image_src = $this->get_category_term_icon_src($data_category->term_id, $args['icon_size']);
						}
						
						if ((isset($this->opts['landing_page_rewrite'])) && ($this->opts['landing_page_rewrite'] == true) && ($this->opts['landing_page_use_rewrite'] == "yes")) {
							$data['categories'][$idx]->bcat_url = trailingslashit($this->opts['landing_page_slug']) . $data_category->slug;
						} else {
							//$data['categories'][$idx]->bcat_url = $this->opts['landing_page_slug'] .'&amp;category_name=' . $data_category->slug;
							$data['categories'][$idx]->bcat_url = add_query_arg(array('category' => $data_category->slug), $this->opts['landing_page_slug']);
						}
						//echo "opts<pre>"; print_r($this->opts); echo "</pre>";
						
						//echo "default cat id[". $this->opts['sites']['category_default'] ."] term_id[". $data_category->term_id ."]<br />";
						if ($data_category->term_id == $this->opts['sites']['category_default']) {
							if (($unassigned_sites) && (is_array($unassigned_sites))) {
								$data['categories'][$idx]->count += count($unassigned_sites);
							}
						}
						
						if (($args['show_style'] == "grid") || ($args['show_style'] == "accordion")) {
							$get_terms_args = array();
							
							$get_terms_args['hide_empty']	=	$args['hide_empty_children'];
							//$get_terms_args['hide_empty']	=	0;

							$get_terms_args['orderby']		=	$args['orderby_children'];
							$get_terms_args['order']		=	$args['order_children'];

							//$get_terms_args['parent'] = $data_category->term_id;
							$get_terms_args['child_of'] = $data_category->term_id;
							$get_terms_args['hierarchical']	=	1;

							//echo "child get_terms_args<pre>"; print_r($get_terms_args); echo "</pre>";

							$child_categories = get_terms( SITE_CATEGORIES_TAXONOMY, $get_terms_args );
							if (($child_categories) && (count($child_categories))) {

								//echo "child_categories<pre>"; print_r($child_categories); echo "</pre>";

								// We tally the count of the children to make sure the parent count shows correctly. 
								$children_count = 0;
								foreach($child_categories as $child_category) {
									//echo "default cat id[". $this->opts['sites']['category_default'] ."] term_id[". $child_category->term_id ."]<br />";
									
									if ($child_category->term_id == $this->opts['sites']['category_default']) {
										if (($unassigned_sites) && (is_array($unassigned_sites))) {
											$child_category->count += count($unassigned_sites);
										}
									}

									$children_count += $child_category->count;
									
									if ((isset($this->opts['landing_page_rewrite'])) && ($this->opts['landing_page_rewrite'] == true) && ($this->opts['landing_page_use_rewrite'] == "yes")) {
										$child_category->bcat_url = trailingslashit($this->opts['landing_page_slug']) . $child_category->slug;
									} else {
										//$child_category->bcat_url = $this->opts['landing_page_slug'] .'&amp;category=' . $child_category->slug;
										$child_category->bcat_url = add_query_arg(array('category' => $child_category->slug), $this->opts['landing_page_slug']);
									}									
									
									if ((isset($args['icon_show_children'])) && ($args['icon_show_children'] == true)) {
										$child_category->icon_image_src = $this->get_category_term_icon_src($child_category->term_id, $args['icon_size_children']);
									}
									
								}
								if ($args['show_style'] == "accordion")
									$data['categories'][$idx]->children_count = $children_count;
								
								$data['categories'][$idx]->children = $child_categories;
								//echo "children<pre>"; print_r($data['categories'][$idx]->children); echo "</pre>";
							}
						}
					}
				}

				if ($args['hide_empty']) {
					foreach($data['categories'] as $cat_parent_idx => $cat_parent) {
						if ((isset($cat_parent->children)) && (count($cat_parent->children))) {
							foreach($cat_parent->children as $cat_child_idx => $cat_child) {
								if ($cat_child->count == 0) {
									unset($data['categories'][$cat_parent_idx]->children[$cat_child_idx]);
								}
							}							
						}
						if ($cat_parent->count == 0) {
							if ( (!isset($cat_parent->children)) || (!count($cat_parent->children)) ) {
								unset($data['categories'][$cat_parent_idx]);
							}
						}
					}
				}

				//echo "data<pre>"; print_r($data); echo "</pre>";
				//die();
				
				//echo "args<pre>"; print_r($args); echo "</pre>";
				if (($args['show_style'] == "ul") || ($args['show_style'] == "ul-nested") 
				 || ($args['show_style'] == "ol") || ($args['show_style'] == "ol-nested")
				 || ($args['show_style'] == "select-flat") || ($args['show_style'] == "select-nested")) {
					$categories_string = apply_filters('site_categories_landing_list_display', $content, $data, $args);
				} else if ($args['show_style'] == "grid") {
					$categories_string = apply_filters('site_categories_landing_grid_display', $content, $data, $args);
				} else if ($args['show_style'] == "accordion") {
					$categories_string = apply_filters('site_categories_landing_accordion_display', $content, $data, $args);
				}
				return $categories_string;
			}
		}
		
		return $content;
	}
	
	/**
	 * 
	 *
	 * @since 1.0.0
	 *
	 * @param none
	 * @return none
	 */
	function process_categories_title ($content, $post_id=0) {

		global $post;

		if (is_admin()) return $content;
		if (!is_multisite()) return $content;
		if (!in_the_loop()) return $content;

		$this->load_config();

		// We get the bcat options. This 'should' contain the variable 'landing_page_id' is the admin properly set things up
		if ((!isset($this->opts['landing_page_id'])) || (!intval($this->opts['landing_page_id'])))
			$opts['landing_page_id'] = 0; 
		
		if ($post->ID != intval($this->opts['landing_page_id'])) return $content;

		$category = '';
		if ($this->opts['landing_page_use_rewrite'] == "yes") {
			$category = get_query_var('category_name');
			$category_int = intval($category);



			// Here is some fuzzy logic. The query_var 'category_name' is the first item off the page slug as in /page-slug/category-name/page-number/
			// So we need to check if it is a real intval (3, 6, 12, etc.) then we assume we don't have a category and we are viewing the top-level page
			// list of blog categories. IF we do have a valid category-name then the next query_var is the page-number
			if (($category == $category_int) && ($category_int != 0)) {
				$category = '';
			}
		} else {
			if (isset($_GET['category'])) {
				$category = esc_attr($_GET['category']);
				//echo "category<pre>"; print_r($category); echo "</pre>";
			}
		}


		if (!$category) return $content;

		$bcat_term = get_term_by("slug", $category, SITE_CATEGORIES_TAXONOMY);
		if ( is_wp_error($bcat_term)) return $content;

		$title_str = '';

		if ((isset($this->opts['categories']['icon_show'])) && ($this->opts['categories']['icon_show'] == true)) {
			
			$icon_image_src = $this->get_category_term_icon_src($bcat_term->term_id, $this->opts['categories']['icon_size']);
			if ($icon_image_src) {
				$title_str .= '<img class="site-category-icon" style="float: left; padding-right:10px" alt="'. $bcat_term->name .'" src="'. $icon_image_src .'" 
					width="'. $this->opts['categories']['icon_size'] .'" height="'. $this->opts['categories']['icon_size'] .'" />';
			}
		} 
		
		$title_str .= '<span class="site-category-title">';
			if ((isset($this->opts['sites']['header_prefix'])) && (strlen($this->opts['sites']['header_prefix']))) {
				$title_str .= '<span class="site-category-header-prefix">'. $this->opts['sites']['header_prefix'] .'</span>';
			}
			
			$title_str .= '<span class="site-category-header-category-name">' ." ". ($bcat_term ? $bcat_term->name : '') .'</span>';
		$title_str .= '</span>';
		
		return $title_str;
	}
		
	/**
	 * 
	 *
	 * @since 1.0.0
	 *
	 * @param none
	 * @return none
	 */
	function bcat_signup_blogform() {
		global $wpdb, $bp;

		$this->load_config();

		if ((!isset($this->opts['sites']['signup_show'])) || ($this->opts['sites']['signup_show'] != 1))
			return;
		
		if (!isset($this->opts['sites']['signup_category_label']))	
			$this->opts['sites']['signup_category_label'] = __('Seitenkategorie:', SITE_CATEGORIES_I18N_DOMAIN);

		if (isset($this->opts['sites']['signup_category_required'])) {
			if (!isset($this->opts['sites']['signup_category_minimum']))
				$this->opts['sites']['signup_category_minimum'] = 1;
		} else {
			$this->opts['sites']['signup_category_minimum'] = 0;
		}

		if (!isset($this->opts['sites']['signup_description_label']))	
			$this->opts['sites']['signup_description_label'] = __('Seitenbeschreibung:', SITE_CATEGORIES_I18N_DOMAIN);

		if (isset($this->opts['sites']['category_limit']))
			$blog_category_limit = intval($this->opts['sites']['category_limit']);
		else
			$blog_category_limit = 1;

		if (($blog_category_limit > 100)	|| ($blog_category_limit < 1))
			$blog_category_limit = 1;

		if (!isset($this->opts['sites']['signup_category_minimum']))
			$this->opts['sites']['signup_category_minimum'] = 1;
		else
			$signup_category_minimum = intval($this->opts['sites']['signup_category_minimum']);

		$terms_count = wp_count_terms(SITE_CATEGORIES_TAXONOMY, array('hide_empty' => false));
		if ($this->opts['sites']['signup_category_parent_selectable'] != 1) {
			$parent_terms = get_terms( SITE_CATEGORIES_TAXONOMY, array('parent' => 0, 'hide_empty' => false) );
			//echo "parent_terms<pre>"; print_r($parent_terms); echo "</pre>";
			$terms_count -= count($parent_terms);
		}

		if (intval($signup_category_minimum) > $terms_count)
			$signup_category_minimum = $terms_count;

		if (intval($blog_category_limit) > $terms_count)
			$blog_category_limit = $terms_count;
			
		//echo "signup_category_minimum[". $signup_category_minimum ."] blog_category_limit[". $blog_category_limit ."] terms_count[". $terms_count ."]<br />";
		//echo "signup_category_parent_selectable[". $this->opts['sites']['signup_category_parent_selectable'] ."]<br />";

		
		// We need to check if we are showing the signup form for WordPress or BuddyPress. For WP we show the Site Categories form elements. But for BP
		// we hide them until the user selected the 'Yes, I'd like to create a new site' checkbox.
		$_site_categories_use_js = false;
		if ((isset($bp->current_component)) && ($bp->current_component == "register")) {
			if ((wp_script_is('jquery', 'enqueued')) || (wp_script_is('jquery', 'done')) || (wp_script_is('jquery', 'to_do'))) {
				$_site_categories_use_js = true;
			}
		}

		$wrapper_style = 'clear:both;';
		if ($_site_categories_use_js == true) {
			$wrapper_style .= " display:none;";
		}
		?>
		<div id="bcat_site_categories_wrapper" style="<?php echo $wrapper_style; ?>">
		<div id="bcat_site_categories_section">
		<label for=""><?php echo stripslashes($this->opts['sites']['signup_category_label']) ?></label>
		<?php
			if ($this->_signup_form_errors) {
				if ( $errmsg = $this->_signup_form_errors->get_error_message('bcat_site_categories') ) { 
					if ($bp) {
						?><div class="error"><?php echo $errmsg ?></div><?php 
					} else {
						?> <p class="error"><?php echo $errmsg ?></p><?php 
					}
				}
			}
		//$site_categories_description = apply_filters('add_site_page_site_categories_description', '');
		//if (!empty($site_categories_description)) {
		//	echo $site_categories_description;
		//}
		
		$cat_counter = 1;
		?><ol><?php
		while(true) {

			$cat_excludes = '';
			if ((is_multisite()) && (!is_super_admin())) {
				if ((isset($this->opts['sites']['category_excludes'])) 
				 && (is_array($this->opts['sites']['category_excludes']))
				 && (count($this->opts['sites']['category_excludes']))) {
					$cat_excludes = implode(',', $this->opts['sites']['category_excludes']);
				} 
			}

			$bcat_args = array(
				'taxonomy'			=>	SITE_CATEGORIES_TAXONOMY,
				'hierarchical'		=>	true,
				'hide_empty'		=>	false,
				'exclude'			=>	$cat_excludes,
				'show_option_none'	=>	__('Keine gew??hlt', SITE_CATEGORIES_I18N_DOMAIN), 
				'name'				=>	'bcat_site_categories['. $cat_counter .']',
				'class'				=>	'bcat_category',
				'orderby'			=> $this->opts['signups']['orderby'],
				'order'				=> $this->opts['signups']['order']
			);
			if (isset($_POST['bcat_site_categories'][$cat_counter])) {
				$bcat_args['selected'] = intval($_POST['bcat_site_categories'][$cat_counter]);
			}
			?><li><?php 
				if ($this->opts['sites']['signup_category_parent_selectable'] == 1)
					wp_dropdown_categories( $bcat_args ); 
				else
					$this->wp_dropdown_categories( $bcat_args ); 
				?> <?php
				if ((isset($this->opts['sites']['signup_category_required'])) && ($this->opts['sites']['signup_category_required'] == 1)) { 
					if ($cat_counter <= intval($signup_category_minimum)) {
						?><span class="site-categories-required"><?php _e('(* ben??tigt)', SITE_CATEGORIES_I18N_DOMAIN); ?></span><?php
					}
				}
			?></li><?php

			$cat_counter += 1;
			if ($cat_counter > $blog_category_limit) 
				break;
		}			
		?></ol></div><?php
		
		?>
		
		<div id="bcat_site_description_section">
			<label for="bcat_site_description"><?php echo stripslashes($this->opts['sites']['signup_description_label']) ?> <?php 
				if ((isset($this->opts['sites']['signup_description_required'])) && ($this->opts['sites']['signup_description_required'] == 1)) { 
					?><span class="site-categories-required"><?php _e('(* ben??tigt)', SITE_CATEGORIES_I18N_DOMAIN); ?></span><?php 
				} ?></label>
			<?php
				//$site_description = apply_filters('new_site_page_site_categories_site_description', '');
				//if (!empty($site_description)) {
				//	echo $site_description;
				//}
			?>
			<?php
				if ($this->_signup_form_errors) {
					if ( $errmsg = $this->_signup_form_errors->get_error_message('bcat_site_description') ) {
						if ($bp) {
							?><div class="error"><?php echo $errmsg ?></div><?php 
						} else {
							?><p class="error"><?php echo $errmsg ?></p><?php 
						}
					}
				}
			?>
			<textarea name="bcat_site_description" style="width:100%;" cols="30" rows="10" id="bcat_site_description"><?php
				if (isset($_POST['bcat_site_description'])) {
					echo $_POST['bcat_site_description'];
				}
			?></textarea><br />
		</div>
		</div>
		<?php
			if ($_site_categories_use_js == true) {
				?>
				<script type="text/javascript">
					jQuery('input#signup_with_blog').click(function() {
						if (jQuery(this).prop('checked')) {
							jQuery('#bcat_site_categories_wrapper').slideDown('slow');
						} else {
							jQuery('#bcat_site_categories_wrapper').slideUp('fast');
						}
					});
				</script>
				<?php
			}		
	}
	
	/**
	 * Validates the new blog signup form on the front-end of the website. If the fields are not valid we set the WP_Error object and return the errors
	 * to be displayed on the form. If valid then we store the form var into a class array which will be used in the 'wpmu_new_blog_proc' function.
	 *
	 * @since 1.0.2
	 *
	 * @param none
	 * @return none
	 */
	function bcat_wpmu_validate_blog_signup($result) {
		global $bp, $errors;
		
		$this->load_config();
		
		if ((!isset($this->opts['sites']['signup_show'])) || ($this->opts['sites']['signup_show'] != 1))
			return $result;
		

		if ((isset($this->opts['sites']['signup_category_required'])) && ($this->opts['sites']['signup_category_required'] == 1)) {

			$terms_count = wp_count_terms(SITE_CATEGORIES_TAXONOMY, array('hide_empty' => false));
			if ($this->opts['sites']['signup_category_parent_selectable'] != 1) {
				$parent_terms = get_terms( SITE_CATEGORIES_TAXONOMY, array('parent' => 0, 'hide_empty' => false) );
				//echo "parent_terms<pre>"; print_r($parent_terms); echo "</pre>";
				$terms_count -= count($parent_terms);
			}
			if (!isset($this->opts['sites']['signup_category_minimum']))
				$this->opts['sites']['signup_category_minimum'] = 1;
			else
				$signup_category_minimum = intval($this->opts['sites']['signup_category_minimum']);
				
			if (intval($signup_category_minimum) > $terms_count)
				$signup_category_minimum = $terms_count;

			if (intval($blog_category_limit) > $terms_count)
				$blog_category_limit = $terms_count;

			//echo "signup_category_minimum[". $signup_category_minimum ."] blog_category_limit[". $blog_category_limit ."] terms_count[". $terms_count ."]<br />";


			//if (!isset($this->opts['sites']['signup_category_minimum']))
			//	$this->opts['sites']['signup_category_minimum'] = 1;

			$bcat_site_categories = array();
			if (isset($_POST['bcat_site_categories'])) {
				foreach($_POST['bcat_site_categories'] as $bcat_cat) {
					if (intval($bcat_cat) > 0) {
						$bcat_site_categories[] = intval($bcat_cat);
					}
				}
			} 
			
			if (count($bcat_site_categories)) {
				$bcat_site_categories = array_unique($bcat_site_categories);
			}
			
			if (count($bcat_site_categories) < $this->opts['sites']['signup_category_minimum']) {
				$format = __('Du musst mindestens %d eindeutige Kategorien ausw??hlen', SITE_CATEGORIES_I18N_DOMAIN);
				$errmsg = sprintf($format, $signup_category_minimum );
					
				$result['errors']->add( 'bcat_site_categories', $errmsg);
				if ($bp) {
					$bp->signup->errors['bcat_site_description'] = $errmsg;
				}
				
			} else {
				$this->bcat_signup_meta['bcat_site_categories'] = $bcat_site_categories;
				
			}
		} 

		if ((isset($this->opts['sites']['signup_description_required'])) && ($this->opts['sites']['signup_description_required'] == 1)) {
			$bcat_site_description = '';
			if (isset($_POST['bcat_site_description'])) {
				$bcat_site_description = esc_attr($_POST['bcat_site_description']);
			}
			
			if (!strlen($bcat_site_description)) {
				$errmsg = __('Bitte gib eine Seiten-Beschreibung an', SITE_CATEGORIES_I18N_DOMAIN);
				$result['errors']->add( 'bcat_site_description',  $errmsg);
				if ($bp) {
					$bp->signup->errors['bcat_site_description'] = $errmsg;
				}
			} else {
				$this->bcat_signup_meta['bcat_site_description'] = $bcat_site_description;
			}
		}
		$this->_signup_form_errors = $result['errors'];
		
		return $result;
	}

	/**
	 * Once the new blog form submits we capture and validate the form information via the function 'bcat_wpmu_validate_blog_signup'. Via
	 * that function we store the bcat related fields into a class array. Via this 'bcat_add_signup_meta' function we store the class array
	 * as part of the signup meta information. 
	 *
	 * This is needed because there are two scenarios for signup. One is an anonymous user creates a new site. During this processing the form information
	 * needs to be stored until the new user is confirmed. So this does not take place all at once. The second scenario is when an authenticated user 
	 * creates a new blog. In this case the form processing is all at once. The meta will still be stored and processed but is only needed for a short 
	 * period of time
	 *
	 * @since 1.0.4
	 *
	 * @param none
	 * @return none
	 */

	function bcat_add_signup_meta($meta) {
		if (is_multisite()) {
			if (isset($this->bcat_signup_meta)) {
				$meta['bcat_signup_meta'] = $this->bcat_signup_meta;
			}
		}
		return $meta;
	}
	
	/**
	 * Once the signup is validated and stored into the wp_signups. The user will received an email with an activation link. When clicked this will trigger
	 * a load of the wp_signups record. And passed to here and other plugins which subscribe to the action. Here we check for a valid blog id and assign 
	 * the previously selected site categories terms and description.
	 *
	 * @since 1.0.2
	 *
	 * @param none
	 * @return none
	 */
	function wpmu_new_blog_proc($blog_id, $user_id, $domain, $path, $site_id, $meta ) {
		global $current_site;

		if ((isset($blog_id)) && ($blog_id)) {

            if( isset($meta['bcat_signup_meta']['bcat_site_categories']) && ! empty( $meta['bcat_signup_meta']['bcat_site_categories'] ) ){
			    $bcat_set = wp_set_object_terms($blog_id, $meta['bcat_signup_meta']['bcat_site_categories'], SITE_CATEGORIES_TAXONOMY);
		    }
		    elseif( ! empty( $this->opts['sites']['category_default'] ) ){
			    $bcat_set = wp_set_object_terms( $blog_id, intval( $this->opts['sites']['category_default'] ), SITE_CATEGORIES_TAXONOMY );
		    }

			if (isset($meta['bcat_signup_meta']['bcat_site_description'])) {
				
                if( ! empty( $meta['bcat_signup_meta']['bcat_site_categories'] ) ){
					$bcat_set = wp_set_object_terms($blog_id, $meta['bcat_signup_meta']['bcat_site_categories'], SITE_CATEGORIES_TAXONOMY);
				}
				elseif( ! empty( $this->opts['sites']['category_default'] ) ){
					$bcat_set = wp_set_object_terms( $blog_id, intval( $this->opts['sites']['category_default'] ), SITE_CATEGORIES_TAXONOMY );
				}
            
			}
		}
	}
	
	function wp_dropdown_categories( $args = '' ) {
		$defaults = array(
			'show_option_all'		=> '', 
			'show_option_none'		=> '',
			'orderby'				=> 'id', 
			'order'					=> 'ASC',
			'show_last_update'		=> 0, 
			'show_count'			=> 0,
			'hide_empty'			=> 1, 
			'child_of'				=> 0,
			'exclude'				=> '', 
			'echo'					=> 1,
			'selected'				=> 0, 
			'hierarchical'			=> 0,
			'name'					=> 'cat', 
			'id'					=> '',
			'class'					=> 'postform', 
			'depth'					=> 0,
			'tab_index'				=> 0, 
			'taxonomy'				=> 'category',
			'hide_if_empty'			=> false
		);

		$defaults['selected'] = ( is_category() ) ? get_query_var( 'cat' ) : 0;

		// Back compat.
		if ( isset( $args['type'] ) && 'link' == $args['type'] ) {
			_deprecated_argument( __FUNCTION__, '3.0', '' );
			$args['taxonomy'] = 'link_category';
		}

		$r = wp_parse_args( $args, $defaults );
		//echo "r<pre>"; print_r($r); echo "</pre>";
		
		if ( !isset( $r['pad_counts'] ) && $r['show_count'] && $r['hierarchical'] ) {
			$r['pad_counts'] = true;
		}

		$r['include_last_update_time'] = $r['show_last_update'];
		extract( $r );

		$tab_index_attribute = '';
		if ( (int) $tab_index > 0 )
			$tab_index_attribute = " tabindex=\"$tab_index\"";

		// Exclude irrelevant arguments, particularly name (which causes no categories to return).
		$term_args = compact(
			"taxonomy",
			"orderby",
			"order",
			"hide_empty",
			"child_of",
			"exclude",
			"hierarchical"
		);
		$categories = get_terms( $term_args );
		$name = esc_attr( $name );
		$class = esc_attr( $class );
		$id = $id ? esc_attr( $id ) : $name;

		if ( ! $r['hide_if_empty'] || ! empty($categories) )
			$output = "<select name='$name' id='$id' class='$class' $tab_index_attribute>\n";
		else
			$output = '';

		if ( empty($categories) && ! $r['hide_if_empty'] && !empty($show_option_none) ) {
			$show_option_none = apply_filters( 'list_cats', $show_option_none );
			$output .= "\t<option value='-1' selected='selected'>$show_option_none</option>\n";
		}

		if ( ! empty( $categories ) ) {

			if ( $show_option_all ) {
				$show_option_all = apply_filters( 'list_cats', $show_option_all );
				$selected = ( '0' === strval($r['selected']) ) ? " selected='selected'" : '';
				$output .= "\t<option value='0'$selected>$show_option_all</option>\n";
			}

			if ( $show_option_none ) {
				$show_option_none = apply_filters( 'list_cats', $show_option_none );
				$selected = ( '-1' === strval($r['selected']) ) ? " selected='selected'" : '';
				$output .= "\t<option value='-1'$selected>$show_option_none</option>\n";
			}

			if ( $hierarchical )
				$depth = $r['depth'];  // Walk the full depth.
			else
				$depth = -1; // Flat.

			$output .= $this->walk_category_dropdown_tree( $categories, $depth, $r );
		}
		if ( ! $r['hide_if_empty'] || ! empty($categories) )
			$output .= "</select>\n";


		$output = apply_filters( 'wp_dropdown_cats', $output );

		if ( $echo )
			echo $output;

		return $output;
	}
	
	function walk_category_dropdown_tree() {
		$args = func_get_args();
		// the user's options are the third parameter
		if ( empty($args[2]['walker']) || !is_a($args[2]['walker'], 'Walker') )
			$walker = new BCat_Walker_CategoryDropdown;
		else
			$walker = $args[2]['walker'];

		return call_user_func_array(array( &$walker, 'walk' ), $args );
	}
	
	function get_unassigned_sites() {
		global $wpdb;
		
		$sql_str = "SELECT $wpdb->blogs.blog_id FROM $wpdb->blogs WHERE $wpdb->blogs.blog_id NOT IN (SELECT DISTINCT $wpdb->term_relationships.object_id
		FROM $wpdb->term_taxonomy LEFT JOIN $wpdb->term_relationships ON $wpdb->term_relationships.term_taxonomy_id=$wpdb->term_taxonomy.term_taxonomy_id 
		WHERE $wpdb->term_relationships.object_id IS NOT NULL AND $wpdb->term_taxonomy.taxonomy='". SITE_CATEGORIES_TAXONOMY ."')";

		//echo "sql_str=[". $sql_str ."]<br />";
		$non_assigned_site_ids = $wpdb->get_col( $sql_str );
		//echo "non_assigned_site_ids<pre>"; print_r($non_assigned_site_ids); echo "</pre>";
		//die();
		return $non_assigned_site_ids;
	}
}

class BCat_Walker_CategoryDropdown extends Walker {
	/**
	 * @see Walker::$tree_type
	 * @since 2.1.0
	 * @var string
	 */
	var $tree_type = 'category';

	/**
	 * @see Walker::$db_fields
	 * @since 2.1.0
	 * @todo Decouple this
	 * @var array
	 */
	var $db_fields = array ('parent' => 'parent', 'id' => 'term_id');

	/**
	 * @see Walker::start_el()
	 * @since 2.1.0
	 *
	 * @param string $output Passed by reference. Used to append additional content.
	 * @param object $category Category data object.
	 * @param int $depth Depth of category. Used for padding.
	 * @param array $args Uses 'selected', 'show_count', and 'show_last_update' keys, if they exist.
	 */
	function start_el(&$output, $category, $depth = 0, $args = array(), $id = 0) {
		$pad = str_repeat('&nbsp;', $depth * 3);

		$cat_name = apply_filters('list_cats', $category->name, $category);

		if ($depth == 0) {
			$output .= "<optgroup class=\"level-$depth\" label=\"".$cat_name."\">";
			
		} else {
			$output .= "\t<option class=\"level-$depth\" value=\"".$category->term_id."\"";
			if ( $category->term_id == $args['selected'] )
				$output .= ' selected="selected"';
			$output .= '>';
			$output .= $pad.$cat_name;
			if ( $args['show_count'] )
				$output .= '&nbsp;&nbsp;('. $category->count .')';
			if ( $args['show_last_update'] ) {
				$format = 'Y-m-d';
				$output .= '&nbsp;&nbsp;' . gmdate($format, $category->last_update_timestamp);
			}
			$output .= "</option>\n";
		}
	}

	function end_el(&$output, $category, $depth = 0, $args = array()) {
		if ($depth == 0) {
			$output .= '</optgroup>';
		}
	}
}

class BCat_Walker_WidgetCategoryDropdown extends Walker {
	/**
	 * @see Walker::$tree_type
	 * @since 2.1.0
	 * @var string
	 */
	var $tree_type = 'category';
	var $prev_depth = 0;
	
	/**
	 * @see Walker::$db_fields
	 * @since 2.1.0
	 * @todo Decouple this
	 * @var array
	 */
	var $db_fields = array ('parent' => 'parent', 'id' => 'term_id');

	function start_lvl( &$output, $depth = 0, $args = array() ) {
		//echo "args<pre>"; print_r($args); echo "</pre>";
		if ($args['show_style'] == "ol-nested") {
			$indent = str_repeat("\t", $depth);
			$output .= "$indent<ul class='children'>\n";
			
		} else if ($args['show_style'] == "ul-nested") {
			$indent = str_repeat("\t", $depth);
			$output .= "$indent<ul class='children'>\n";

		} 
		else if ($args['show_style'] == "select-nested") {
//			$indent = str_repeat("\t", $depth);
//			$output .= "$indent<select class='children children-$depth'>\n";
		}
	}

	function end_lvl( &$output, $depth = 0, $args = array() ) {
		if ($args['show_style'] == "ol-nested") {
			if ($args['show_style'] == "ol-nested")
				$indent = str_repeat("\t", $depth);
			else
				$indent = '';
			$output .= "$indent</ul>\n";
		} else if ($args['show_style'] == "ul-nested") {
			if ($args['show_style'] == "ul-nested")
				$indent = str_repeat("\t", $depth);
			else
				$indent = '';
			$output .= "$indent</ul>\n";
		} 
	}

	function start_el(&$output, $category, $depth = 0, $args = array(), $id = 0) {

		if ($this->prev_depth != $depth) {
			$this->prev_depth = $depth;
		}
		
		$cat_name = apply_filters('list_cats', $category->name, $category);		

		if ($category->count > 0) {
			$output_url = $category->bcat_url;
			$disabled = '';
		} else {
			$output_url = '';
			$disabled = ' onclick="return false;" ';
		}
				
		if (($args['show_style'] == "select-nested") || ($args['show_style'] == "select-flat")) {
			if ($args['show_style'] == "select-nested")
				$pad = str_repeat('&nbsp;', $depth * 3);
			else
				$pad = '';
			
			$output .= '<option class="level-' . $depth . '" value="'. $output_url .'"';
			//if ((isset($args['selected'])) && ( $category->term_id == $args['selected'] ))
			//	$output .= ' selected="selected"';
			$output .= '>';
			$output .= $pad.$cat_name;
			if ( $args['show_counts'] )
				$output .= '&nbsp;&nbsp;('. $category->count .')';
			$output .= "</option>\n";
			
		} else if (($args['show_style'] == "ul") || ($args['show_style'] == "ul-nested") 
				|| ($args['show_style'] == "ol") || ($args['show_style'] == "ol-nested")) {

			$option_spacer = str_repeat('&nbsp;', $depth);

			$output .= '<li class="level-'. $depth .'">';
			
			if ($category->count > 0) {
				$output .= '<a id="site-categories-" href="'. $output_url .'">';
			}
			
			if ( ($args['icon_show'] == true) && (isset($category->icon_image_src))) {
				$output .= '<img class="site-category-icon" width="'. $args['icon_size'] .'" height="'. $args['icon_size'] .'" alt="'. $category->name .'" src="'. $category->icon_image_src .'" />';
			} 
			$output .= '<span class="site-category-title">'. $category->name .'</span>';
			if ($category->count > 0) {
				$output .= '</a>';
			}
			
			if ($args['show_counts']) {
				$output .= '<span class="site-category-count">('. $category->count .')</span>';
			}
			
			if (($args['show_description']) && (strlen($category->description))) {						

				if (($args['show_style'] == "ul") || ($args['show_style'] == "ul-nested") 
				 || ($args['show_style'] == "ol") || ($args['show_style'] == "ol-nested")) {

					$bact_category_description = wpautop(stripslashes($category->description));						
					$bact_category_description = str_replace(']]>', ']]&gt;', $bact_category_description);
					if (strlen($bact_category_description)) {
						$output .= '<div class="site-category-description">'. $bact_category_description .'</div>';
					}
				}
			}
		}
	}

	function end_el( &$output, $category, $depth = 0, $args = array() ) {
		if (($args['show_style'] == "ol-nested") || ($args['show_style'] == "ul-nested")) {
			$output .= "</li>\n";
		}
	}
	
}

$site_categories = new SiteCategories();
