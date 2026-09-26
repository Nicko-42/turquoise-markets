<?php
/*******************************************************************************
 * WP environment tweaks & TI branding
 * 
 * turq_admin.php
 * 
*******************************************************************************/
//do_action( 'qm/debug', );
//error_log("product : ".print_r($product,true));

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

//Record user's last login to custom meta
add_action( 'wp_login', function($user_login, $user) {update_user_meta( $user->ID, 'last_login', time() );}, 10, 2 );

//Register new custom column with last login time
add_filter( 'manage_users_columns', function ($columns) {
    $columns['last_login'] = 'Last Login';
    return $columns;
});

// Output last login column onto WP Front end
add_filter( 'manage_users_custom_column', function ($output, $column_id, $user_id){
    if( $column_id == 'last_login' ) {
        $last_login = get_user_meta( $user_id, 'last_login', true );
        $output = $last_login ? '<div>'.date('d/m/Y @ g:i a', $last_login ).' ['.human_time_diff( $last_login ).' ago]</div>' : 'No record';
    }
    return $output;
}, 10, 3 );


// MEDIA FILESIZE COLUMN
add_filter('manage_upload_columns', function($columns) {
    $columns['filesize_mb'] = 'File Size';
    return $columns;
});

add_action('manage_media_custom_column', function($column_name, $post_id) {
    if ($column_name === 'filesize_mb') {
        $file_path = get_attached_file($post_id);
        if (file_exists($file_path)) {
            $bytes = filesize($file_path);

			if     ($bytes < 500 * 1024)		$class = 'size-small';       // < 500 KB
			elseif ($bytes < 2 * 1024 * 1024)	$class = 'size-medium';      // < 2 MB
			elseif ($bytes < 5 * 1024 * 1024)	$class = 'size-large';       // < 5 MB
			else								$class = 'size-huge';        // ≥ 5 MB

			echo '<span class="media-filesize ' . esc_attr($class) . '">';			
			if ($bytes < 1024 * 1024) {
				$size_kb = number_format($bytes / 1024, 1);
				echo $size_kb . ' KB';
			} else {
				$size_mb = number_format($bytes / 1024 / 1024, 2);
				echo $size_mb . ' MB';
			}
			
		} else {
			echo '—';
		}
	echo '</span>';
    }
}, 10, 2);



add_action('admin_head', function () {
	$bc = "#f15d22";
	echo "<style>
	/* branding */
		#wpadminbar {border-bottom:2px solid $bc;}
		#wpcontent {border-left:2px solid $bc}
		.woocommerce-layout__header {border-bottom: 2px solid$bc}
		#adminmenu li.wp-menu-separator {height:2px;margin: 6px 0;background-color: $bc}
	
	/* right column block editor headings */
		.block-editor-block-inspector .components-panel__body button {font-weight:bolder}
		.block-editor-block-inspector .components-panel__body.is-opened {background-color: #fbfbfb;}
		.block-editor-block-inspector .components-panel__body.is-opened .components-panel__body-title:not(:hover) {background-color: #fef8ee;}					

    /* order list */

    /* product entry meta */	

	/* remmove plugin pro adverts etc... */
	
	
	/* media filesizes */
		.media-filesize {
			font-weight: 600;
			padding: 2px 6px;
			border-radius: 4px;
			white-space: nowrap;
		}

		.size-small  { background:#e7f7ed; color:#1d7f3a; } /* green */
		.size-medium { background:#fff4e5; color:#9a5b00; } /* amber */
		.size-large  { background:#ffe6e6; color:#b20000; } /* red */
		.size-huge   { background:#d10000; color:#fff; }   /* 🔥 */
	
	</style>";
});

// Right hand column adjustable/resize in admin for block settings
function toast_enqueue_jquery_ui(){
	wp_enqueue_script( 'jquery-ui-resizable');
}
add_action('admin_enqueue_scripts', 'toast_enqueue_jquery_ui');


add_action('admin_head', function(){ ?>
	<style>
		.interface-interface-skeleton__sidebar .interface-complementary-area, .interface-interface-skeleton__sidebar .interface-complementary-area__fill{width:100% !important;}
		.interface-interface-skeleton__sidebar .interface-complementary-area .block-editor-block-inspector * {max-width: unset !important;}
		/*.edit-post-layout:not(.is-sidebar-opened) .interface-interface-skeleton__sidebar{display:none;}*/
		/*.is-sidebar-opened .interface-interface-skeleton__sidebar{width:350px;}*/

		/*UI Styles*/
		.ui-dialog .ui-resizable-n {height: 2px;top: 0;}
		.ui-dialog .ui-resizable-e {width: 2px;right: 0;}
		.ui-dialog .ui-resizable-s {height: 2px;bottom: 0;}
		.ui-dialog .ui-resizable-w {width: 2px;left: 0;}
		.ui-dialog .ui-resizable-se,
		.ui-dialog .ui-resizable-sw,
		.ui-dialog .ui-resizable-ne,
		.ui-dialog .ui-resizable-nw {width: 7px;height: 7px;}
		.ui-dialog .ui-resizable-se {right: 0;bottom: 0;}
		.ui-dialog .ui-resizable-sw {left: 0;bottom: 0;}
		.ui-dialog .ui-resizable-ne {right: 0;top: 0;}
		.ui-dialog .ui-resizable-nw {left: 0;top: 0;}
		.ui-draggable .ui-dialog-titlebar {cursor: move;}
		.ui-draggable-handle {-ms-touch-action: none;touch-action: none;}
		.ui-resizable {position: relative;}
		.ui-resizable-handle {position: absolute;font-size: 0.1px;display: block;-ms-touch-action: none;touch-action: none;}
		.ui-resizable-disabled .ui-resizable-handle,
		.ui-resizable-autohide .ui-resizable-handle {display: none;}
		.ui-resizable-n {cursor: n-resize;height: 7px;width: 100%;top: -5px;left: 0;}
		.ui-resizable-s {cursor: s-resize;height: 7px;width: 100%;bottom: -5px;left: 0;}
		.ui-resizable-e {cursor: e-resize;width: 7px;right: -5px;top: 0;height: 100%;}
		.ui-resizable-w {cursor: w-resize;width: 7px;left: -5px;top: 0;height: 100%;}
		.ui-resizable-se {cursor: se-resize;width: 12px;height: 12px;right: 1px;bottom: 1px;}
		.ui-resizable-sw {cursor: sw-resize;width: 9px;height: 9px;left: -5px;bottom: -5px;}
		.ui-resizable-nw {cursor: nw-resize;width: 9px;height: 9px;left: -5px;top: -5px;}
		.ui-resizable-ne {cursor: ne-resize;width: 9px;height: 9px;right: -5px;top: -5px;}
	</style>

	<script>
		jQuery(window).ready(function(){
    		setTimeout(function(){
        		jQuery('.interface-interface-skeleton__sidebar').width(localStorage.getItem('toast_sidebar_width'))
        		jQuery('.interface-interface-skeleton__sidebar').resizable({
            		handles: 'w',
            		resize: function(event, ui) {
                		jQuery(this).css({'left': 0});
                		localStorage.setItem('toast_sidebar_width', jQuery(this).width());
           				}
        		});
    		}, 500)
		});
	</script>
<?php });


// WP login area and admin customisation
add_action( 'login_enqueue_scripts', function () { ?>
    <style type="text/css">
        body { background: url("<?php $upload_dir = wp_upload_dir(); echo $upload_dir['baseurl'];  ?>/2024/11/our-story-page-1.jpg") 65% 25% no-repeat !important; background-size:cover !important; display: flex; justify-content: flex-end; align-items: flex-end; flex-direction: column}
		html #login {background-color: #eacc9a; margin: 0; padding:0; min-height: 100vh; display: flex;  flex-direction: column; justify-content: center}
		#loginform {border:none; background-color:unset; margin-top: 40px;box-shadow:none}
        #login h1 a, .login h1 a {
			background-image: url("<?php $upload_dir = wp_upload_dir(); echo $upload_dir['baseurl'];  ?>/2024/10/SussexPeasantMasterLogoBlack.png");
			width:320px;height:130px;
			background-size:contain;
			background-repeat: no-repeat;
        }
		#login > p, .language-switcher {display:none}
		#ti_cta {background-color:#7eaa3e; width: 100%; padding: 20px}
		#ti_cta a, #ti_cta a:focus, #ti_cta a:visited {text-decoration: none;color:#444;text-align: center;display: block;box-shadow:none}
		#ti_cta a:hover {color:#fff}
		.login #login_error, .login .message {border-top: 10px solid red; border-left: none; margin: 20px 0 0 0;}
    </style>
<?php });

/* add_filter( 'login_message',	function()		{ ?> <div id="ti_cta"><a href="https://turquoise-internet.co.uk">Wordpress development by Turquoise Internet</a></div> <?php } ); */
add_action( 'login_footer',	function()		{ ?> <div id="ti_cta"><a href="https://turquoise-internet.co.uk">Wordpress development by Turquoise Internet</a></div> <?php }, 9999);
add_filter( 'login_headerurl',	function()		{ return home_url(); } );
add_filter( 'login_headertext', function()		{ return get_option( 'blogname' ); } );
add_filter('admin_footer_text', function($text)	{$text = 'This website was developed in Wordpress by <a href="https://turquoise-internet.co.uk">Turquoise Internet</a>'; return $text;}); //left side


// last modified date column in admin screen
function heirch_columns( $column, $post_id ) {
	switch ( $column ) {
	case 'modified':
		$m_orig		= get_post_field( 'post_modified', $post_id, 'raw' );
		$m_stamp	= strtotime( $m_orig );
		$modified	= date('j/n/y @ g:i a', $m_stamp );
	       	$modr_id	= get_post_meta( $post_id, '_edit_last', true );
	       	$auth_id	= get_post_field( 'post_author', $post_id, 'raw' );
	       	$user_id	= !empty( $modr_id ) ? $modr_id : $auth_id;
	       	$user_info	= get_userdata( $user_id );
	
	       	echo '<p class="mod-date">';
	       	echo '<em>'.$modified.'</em><br />';
	       	echo 'by <strong>'.$user_info->display_name.'<strong>';
	       	echo '</p>';
		break;
	// end all case breaks
	}
};

function page_columns( $columns ) {
	$columns['modified']	= 'Last Modified';
	return $columns; 
};

function last_modified_column_register_sortable( $columns ) {
	$columns["modified"] = "modified";
        return $columns;
};

add_action('after_setup_theme', function(){
	add_action ( 'manage_pages_custom_column',	'heirch_columns',	10,	2	);
	add_action ( 'manage_posts_custom_column',	'heirch_columns',	10,	2	);

	add_filter ( 'manage_edit-page_columns',	'page_columns'				);
	add_filter ( 'manage_edit-post_columns',	'page_columns'			);

	add_filter( "manage_edit-post_sortable_columns", "last_modified_column_register_sortable" );
	add_filter( "manage_edit-page_sortable_columns", "last_modified_column_register_sortable" );
	
	});


// quick access to re-usable blocks
add_action('admin_menu', function(){
  add_theme_page(
    'Reusable Blocks',
    'Reusable Blocks',
    'administrator',
    'site-editor.php?p=%2Fpattern&postType=wp_block&categoryId=my-patterns'
  );
	
});


/*
add_action('get_header', function(){
        $user = get_userdata( get_current_user_id() );
		if (is_user_logged_in() && in_array( 'administrator', (array) $user->roles ) )	return;
		wp_die( '<style>html{background-color:#006500}</style><h1 style="color:red">Sorry - our site is offline for essential maintenance.</h1><p>We sincerely apologise for any inconvience whilst we upgrade the site.</p>', 'Sorry - our site is offline for essential maintenance and upgrading');
});
*/
?>