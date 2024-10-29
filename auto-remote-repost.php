<?php
/*
 Plugin Name: Automated Remote Reposting
 Plugin URI: http://www.regionad.co.uk/automated-remote-reposting/
 Description: Reposts/clones the currently added post on a remote Wordpress website/domain.
 Version: 1.0.1
 Author: SomeDev
 Author URI: http://www.regionad.co.uk
 Text Domain: auto-remote-repost
 */

/*  Copyright 2009-2019	SomeDev  (email : somedev@gmx.com)
*/

if (! defined('ABSPATH')) {
    die('Invalid request.');
}

/*if(function_exists('wp_get_current_user')) {
  $user = wp_get_current_user();
  //$allowed_roles = array('editor', 'administrator', 'author');
  $allowed_roles = array('editor', 'administrator');
  if( !array_intersect($allowed_roles, $user->roles ) ) {
     exit('Sorry, you cannot access this page.');
  }
}*/

//Get the saved plugin TYPE (source/destiantion) for the current domain
$plugin_dir_path = dirname(__FILE__);
/*$saved_source_destination = file_get_contents($plugin_dir_path.'/source_destination.txt');
$saved_source_destination = trim($saved_source_destination);*/
$saved_source_destination = get_option('wp_arrp_dradcom_source_destination');
error_log('$saved_source_destination: '.$saved_source_destination);

//If this domain is 'source', enable the functions for it
if ($saved_source_destination == 'source') {
    //Automated posting to Remote Website/Server on post adding
    /**
     * Save post metadata when a post is saved.
     *
     * @param int $post_id The post ID.
     * @param post $post The post object.
     * @param bool $update Whether this is an existing post being updated or not.
     */
    function save_post_callback($post_id, $post, $update)
    {

      //FOR THE CATEGORIES READING TO WORK WE NEED TO DISABLE THE "Enable Pre-publish Checks" (in Gutenberg editor)

        /*if ( $update && !empty($post->post_title)) {
            return;
        }*/

        //Check new vs update
        //https://wordpress.stackexchange.com/questions/48678/check-for-update-vs-new-post-on-save-post-action
        $myPost        = get_post($post_id);
        $post_created  = new DateTime($myPost->post_date_gmt);
        $post_modified = new DateTime($myPost->post_modified_gmt);


        //if( abs( $post_created->diff( $post_modified )->s ) <= 1 ){
        if (abs($post_created->diff($post_modified)->s) >= 1 || $post->post_type != 'post') {
            // Updated post
            return;
        }

        if ($post->post_status == 'publish') {
            $title = $post->post_title;
            $content = $post->post_content;

            //$categories_arrp = get_the_category($post_id);
            $categories_arr = wp_get_post_categories($post_id);

            // Read JSON file
            $plugin_dir_path = dirname(__FILE__);

            $saved_source_destination = get_option('wp_arrp_dradcom_source_destination');
            $saved_settings = get_option('wp_arrp_dradcom_settings_'. trim($saved_source_destination));
            //$json = file_get_contents($plugin_dir_path.'/settings_source.json');
            //Decode JSON
            //$json_settings = json_decode($json, true);
            $remote_url = $saved_settings['remote_url'];
            $token = $saved_settings['token'];
            $categories_mapping = $saved_settings['categs_mapping'];

echo ('$remote_url: ' . $remote_url);
            //$categories_swap_arr = [];
            //Print data

            //Replace the Source categs with the mapped Destination ones
            foreach ($categories_arr as $key => &$value) {
                if (!empty($categories_mapping[$value])) {
                    $categories_swap_arr[] = $categories_mapping[$value];
                }
            }

            $categories = implode(",", $categories_swap_arr);

            $data = array(
                        'title' => $title,
                        'content' => $content,
                        'categories' => $categories,
                        'token' => $token
                      );

            $payload = json_encode($data);

            $result = wp_remote_post(
                $remote_url.'/wp-json/dc/auto_post',
                array(
              'method' => 'POST',
              'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
              'body' => $payload
              )
            );
        }
    }
    //add_action( 'save_post', 'save_post_callback', 10, 3 );
    add_action('save_post', 'save_post_callback', 200, 3);
}


//If this domain is 'destination', enable the functions for it
if ($saved_source_destination == 'destination') {

    //Automated posting to Local Server from a Source Website on post adding

    add_action('rest_api_init', 'arrp_dradcom_register_routes');

    /**
     * Register the /wp-json/myplugin/v1/someurl route
     */
    function arrp_dradcom_register_routes()
    {
        register_rest_route('dc', '/auto_post', array(
            //'methods'  => WP_REST_Server::READABLE,
            'methods'  => WP_REST_Server::CREATABLE,
            'callback' => 'arrp_dradcom_serve_route',
        ));
    }

    /**
     * Generate results for the /wp-json/myplugin/v1/someurl route.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response|WP_Error The response for the request.
     */
    function arrp_dradcom_serve_route(WP_REST_Request $request)
    {
        // Do something with the $request

        //$plugin_dir_path = dirname(__FILE__);
        //$json = file_get_contents($plugin_dir_path.'/settings_destination.json');
        //$json_settings = json_decode($json, true);

        $saved_source_destination = get_option('wp_arrp_dradcom_source_destination');
        $saved_settings = get_option('wp_arrp_dradcom_settings_'. trim($saved_source_destination));

        $token = $saved_settings['token'];
        //echo '$token '.$token;

        if ($request['token'] == $token) {
            // return 'TOKEN OK';

            // Gather post data.
            $my_post = array(
             'post_title'    => $request['title'],
             'post_content'  => $request['content'],
             'post_status'   => 'publish',
             'post_author'   => 1,
             'post_category' => explode(',', $request['categories'])
         );

            // Insert the post into the database.
            //wp_insert_post( $my_post );
            $post_id = wp_insert_post($my_post, true);

            if ($post_id) {
                return 'REMOTELY CREATED POST ID: ' . $post_id;
            }
        } else {
            return 'ERROR! WRONG OR NO TOKEN!';
        }
    }
}


// create admin plugin settings menu and page
add_action('admin_menu', 'arrp_dradcom_add_settings_menu');
function arrp_dradcom_add_settings_menu()
{

  //create new top-level menu
    $page_title = 'Auto Remote Repost';
    $menu_title = 'ARRP Settings';
    $capability = 'manage_options';
    $menu_slug  = 'arrp_dradcom_edit_settings';
    $function   = 'arrp_dradcom_edit_settings';
    $icon_url   = 'dashicons-external';
    $position   = 4;

    add_menu_page(
        $page_title,
        $menu_title,
        $capability,
        $menu_slug,
        $function,
        $icon_url,
        $position
  );
}

function arrp_dradcom_edit_settings()
{
    $plugin_dir_path = dirname(__FILE__);

    error_log('Got here');

    //if the form is actually submitted, save the fields in the json source/destination file
    if (isset($_POST['save_settings'])) {

      error_log('Saving settings');

          // Firstly check for nonce before doing nything with the POST data
        if (!isset($_POST['nonce_arrp_dradcom']) || !wp_verify_nonce($_POST['nonce_arrp_dradcom'], 'nonce_arrp_dradcom_check')) {
            print 'Sorry, your nonce did not verify.';
            exit;
        } else {
            // process form data
            $sani_token = sanitize_text_field($_POST['token']);
            $settings_gather = [];

            if (trim(sanitize_text_field($_POST['source_destination'])) == 'Source') {

            /*echo '<pre>';
            var_dump($_POST);*/

                $sani_remote_url = sanitize_text_field($_POST['remote_url']);

                $settings_gather = '{';
                $settings_gather .= '"remote_url":"' . $sani_remote_url . '",';
                $settings_gather .= '"token":"' . $sani_token . '",';

                $settings_gather .= '"categs_mapping":{';

                $p = 0;
                $ctg = 0;
                $plen = count($_POST);
                foreach ($_POST as $pkey => $pvalue) {
                    //echo ' / ' . $p . ' | ' . $ctg . ' - '. $plen .' | ' ;
                    if (strstr($pkey, 'categ_lcl')) {
                        ${"sani_categ_lcl_".$ctg} = sanitize_text_field($_POST['categ_lcl_'.$ctg]);
                        ${"sani_categ_rmt_".$ctg} = sanitize_text_field($_POST['categ_rmt_'.$ctg]);

                        $x = 1;
                        $settings_gather .= '"' . ${"sani_categ_lcl_".$ctg} . '":"' . ${"sani_categ_rmt_".$ctg} . '"';
                        $ctg++;
                    }

                    if (strstr($pkey, 'categ_rmt')) {
                        if ($p < $plen - 4) {
                            $settings_gather .= ',';
                        }
                        $p++;
                        continue;
                    }
                    $p++;
                }

                $settings_gather .= '} }';
                $settings_gather_arr = json_decode($settings_gather, true);
            } else {
                $settings_gather = '{';
                $settings_gather .= '"token":"' . $sani_token . '"';
                $settings_gather .= '}';
                $settings_gather_arr = json_decode($settings_gather, true);
            }

            //$result = file_put_contents($plugin_dir_path.'/settings_' . strtolower(trim($_POST['source_destination'])) . '.json', $settings_gather);
            //$result_saved_source_destination = file_put_contents($plugin_dir_path.'/source_destination.txt', strtolower($_POST['source_destination']));

            if (!get_option('wp_arrp_dradcom_source_destination')) {
                //$result = file_put_contents($plugin_dir_path.'/settings_' . strtolower(trim($_POST['source_destination'])) . '.json', $settings_gather);
                $result = add_option('wp_arrp_dradcom_settings_' . strtolower(trim(sanitize_text_field($_POST['source_destination']))), $settings_gather_arr);
                $result_saved_source_destination = add_option('wp_arrp_dradcom_source_destination', strtolower(sanitize_text_field($_POST['source_destination'])));
            } else {
                $result = update_option('wp_arrp_dradcom_settings_' . strtolower(trim($_POST['source_destination'])), $settings_gather_arr);
                $result_saved_source_destination = update_option('wp_arrp_dradcom_source_destination', strtolower(sanitize_text_field($_POST['source_destination'])));
                error_log ('$result1: ' . print_r( $result, TRUE));
            }
            //echo '$result_saved_source_destination: ';  var_dump($result_saved_source_destination);

            //chmod($plugin_dir_path.'/settings.json', 0664);

            // if ($result === TRUE && $result_saved_source_destination === TRUE) {
            if ($result === TRUE) {
                //var_dump($result);
                //error_log('PRINT NOTIFICATION');
             ?>
      				<div class="notice notice-success is-dismissible">
      					<p><?php _e('Plugin settings saved', 'auto-remote-repost-source'); ?></p>
      				</div>
      			<?php
            //add_action( 'admin_notices', 'arrp_admin_save_notice' );
            }
        }
    } //EndIf nonce check


    //if the admin just changed the souce/destination menu, save only that via the Options API
    if (isset($_POST['source_destination']) && !isset($_POST['save_settings'])) {
        //echo 'source_destination from menu changing: ' . $_POST['source_destination'] . '<br>';
        //$result_saved_source_destination = file_put_contents($plugin_dir_path.'/source_destination.txt', strtolower($_POST['source_destination']));
        //$result_saved_source_destination = add_option('wp_arrp_dradcom_source_destination', strtolower($_POST['source_destination']), '', 'yes');
        if (!get_option('wp_arrp_dradcom_source_destination')) {
            $result_saved_source_destination = add_option('wp_arrp_dradcom_source_destination', strtolower(sanitize_text_field($_POST['source_destination'])));
        } else {
            $result_saved_source_destination = update_option('wp_arrp_dradcom_source_destination', strtolower(sanitize_text_field($_POST['source_destination'])));
        }
        //echo '<br>$result_saved_source_destination: ' . $result_saved_source_destination. '<br>';
          //add_option('wp_arrp_dradcom_source_destination', strtolower($_POST['source_destination']));
    }

    /* ------------------- */

    //Now get the saved source/destination from the local file
    //$saved_source_destination = file_get_contents($plugin_dir_path.'/source_destination.txt');
    $saved_source_destination = get_option('wp_arrp_dradcom_source_destination');
    $saved_settings = get_option('wp_arrp_dradcom_settings_'. trim($saved_source_destination));

    //var_dump($saved_settings);

    //$json = file_get_contents($plugin_dir_path.'/settings_' . trim($saved_source_destination) . '.json');

    //echo 'get_source_dest: '; var_dump ($saved_source_destination);

    //echo ($json);

    //Decode JSON
    //$json_settings = json_decode($json, true);

    if (trim($saved_source_destination) == 'source') {
        $remote_url = $saved_settings['remote_url'];
        $categories_mapping = $saved_settings['categs_mapping'];
        //$categories = implode( ",", $categories_mapping );
        $token = $saved_settings['token'];
    } else {
        $token = $saved_settings['token'];
    }

    if (empty($token)) {
        $token = wp_create_nonce('arrp_dradcom_dradcom-plugin');
    }

    $categories = '';
    if (isset($categories_mapping) && gettype($categories_mapping) == 'array') {
        $i = 0;
        $len = count($categories_mapping);
        $categories .= '<div id="categs_wrapper">';
        foreach ($categories_mapping as $key => &$value) {
            $categories .= '<div class="categs_set">';
            $categories .= '<input type="text" size="5" name="categ_lcl_'.$i.'" value="'.$key.'" class="categ_lcl">';
            $categories .= '<input type="text" size="5" name="categ_rmt_'.$i.'" value="'.$value.'">';
            $categories .= ' <a href="#" id="del_categ_mapping" class="del_categ_mapping">- Del</a><br>';
            $categories .= '</div>';
            $i++;
        }
        $categories .= '</div>';
    }/*else{
    $categories = $categories_mapping;
}*/ ?>

  <div class="wrap">
    <h1>Auto Remote Repost Settings</h1>

    <form method="post" action="">
      <table class="form-table">
        <tr valign="top">
          <td colspan="3" style="text-align:left;">
          <b>Notes</b>:<br>
					- Choose the <b>type</b> you want to set for this domain (source or destination). <b>Attention!</b> when you change it, the form modifications will be lost if you don't save them.<br>
					- The <b>token</b> is auto-generated, but you can edit it too. You need to copy it from here and paste it on the destination domain/website.<br>
					- In case you chose "Source", add the URL of the <b>remote domain</b>.<br>
					- The <b>categories mappings</b> are taken from the destination domain, from the posts taxonomy/category.
          </td>
        </tr>
				<tr>
					<th scope="row">This domain is:</th>
					<td>
						<!--<select id="source_destination" name="source_destination" onchange="toggleSourceDestination()">-->
						<select id="source_destination" name="source_destination" onchange="this.form.submit()">
							<option id="Source" value="Source" <?php if (trim($saved_source_destination) == 'source') {
        echo ' selected';
    } ?>>Source</option>
							<option id="Destination" value="Destination" <?php if (trim($saved_source_destination) == 'destination') {
        echo ' selected';
    } ?>>Destination</option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row">Token:</th>
					<td>
						<input type="text" id="token" name="token" size="60" value="<?php echo esc_attr($token); ?>"/>
					</td>
				</tr>
				<?php if (trim($saved_source_destination) == 'source') { ?>
				<tr id="remote_url_wrp">
					<th scope="row">Remote URL:</th>
					<td>
						<input type="text" id="remote_url" name="remote_url" size="60" value="<?php echo esc_attr($remote_url); ?>" />
					</td>
				</tr>
        <tr id="categs_wrp">
					<th scope="row">Categories Mapping:</th>
          <td id="categs_td">
						<span style="font-size:0.8em;">Local categ. : Remote categ.</span><br>
      <?php echo $categories; ?>
          </td>
        </tr>
				<tr id="categs_add_wrp">
					<th scope="row"></th>
					<td id="categs_td">
					<a href="#" id="add_categ_mapping">+ Add category mapping</a>
					</td>
				</tr>
			<?php } ?>
      </table>
			<script>
				jQuery(".del_categ_mapping").click(function() {
	    		console.log("click");
					//jQuery(this).hide();
					jQuery(this).parent('.categs_set').remove();
				});

				jQuery( "#add_categ_mapping" ).on( "click", function() {
				  //console.log( jQuery( '.categ_lcl' ).length );
					var categ_len = jQuery( '.categ_lcl' ).length;
					//var categ_len_inc = categ_len + 1;
					jQuery('#categs_wrapper').append( '<div class="categs_set"> <input type="text" size="5" name="categ_lcl_' + categ_len + '" value="" class="categ_lcl"><input type="text" size="5" name="categ_rmt_' + categ_len + '" value=""> <a href="#" id="del_categ_mapping" class="del_categ_mapping">- Del</a><br></div>' );
				});

				function toggleSourceDestination(){
					if(jQuery('#source_destination').val() == 'Destination'){
						jQuery('#Destination').attr('selected','selected');
						jQuery('#Source').prop('selected',false);
						jQuery('#remote_url_wrp').hide();
						jQuery('#categs_wrp').hide();
						jQuery('#categs_add_wrp').hide();
						jQuery('#token').attr('readonly','readonly');
					}else{
						jQuery('#Destination').prop('selected',false);
						jQuery('#Source').attr('selected','selected');
						jQuery('#remote_url_wrp').show();
						jQuery('#categs_wrp').show();
						jQuery('#categs_add_wrp').show();
						jQuery('#token').prop('readonly',false);
					}
				}
			</script>

      <?php wp_nonce_field('nonce_arrp_dradcom_check', 'nonce_arrp_dradcom'); ?>
      <?php submit_button(__('Save Settings', 'textdomain'), 'primary', 'save_settings'); ?>

    </form>
  </div>
<?php
} ?>
