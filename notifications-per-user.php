<?php
/*
	Plugin Name: Notifications Per User
	Plugin URI: http://caorda.com/
	Description: Allows email notifications to be applied on a per-user basis instead of for all moderators
	Author: Eric McNiece
	Version: 1.0
	Author URI: http://www.caorda.com/about-caorda/meet-the-team/eric-mcniece/
*/

if ( !class_exists('notificationsPerUser')){
	class notificationsPerUser {

		public function __construct(){
			global $pagenow;

			// Workhorse: Adjust valid notification emails as needed
			add_filter('comment_moderation_recipients', array($this, 'npu_notify_moderator') );

			// Add column to users table
			add_filter( 'manage_users_columns', array($this, 'npu_modify_user_table' ) );
			add_filter( 'manage_users_custom_column', array($this, 'npu_modify_user_table_row' ), 10, 3 );

			// Insert option on to profile edit page
			add_action( 'profile_personal_options', array($this, 'npu_extra_profile_fields' ) );

			// Update meta option on profile save
			add_action( 'personal_options_update', array($this, 'npu_update_user_meta' ) );
			add_action( 'edit_user_profile_update', array($this, 'npu_update_user_meta' ) );

			//ajax function for users.php list
			if (is_admin()  && $pagenow=='users.php'){
				add_filter('admin_footer',array($this,'npu_insert_ajax_status_script'));
			}
			add_action('wp_ajax_npu_change_status', array($this,'npu_ajax_change_status'));

		}

		/**
		 * Reduces notification email list to selected users.
		 *
		 * Filters wp_notify_moderator() in /wp-includes/pluggable.php
		 * 
		 * Treat this as an opt-out case so that we don't accidentally 
		 * include non-moderator users in this list.
		 *
		 * @since 1.0
		 *
		 * @param array $emails Moderator emails to be notified
		 * @param int $comment_id Comment with recent activity (unused)
		 * @return array $emails for use in @wp_mail()
		 */
		public function npu_notify_moderator($emails, $comment_id){

			$users = get_users(array(
				'meta_key'		=> 'recEmailNotification',
				'meta_value'	=> '1',
				'meta_compare'	=> '!='
			));

			foreach($emails as $k=>$email){
				foreach($users as $user){
					if( $email == $user->data->user_email){
						unset($emails[$k]);
						break; break;	// A quick death is honorable
					}
				}
			}

			return $emails;
		}

		
		/**
		 * Adds a column to the /wp-admin/users.php table
		 *
		 * @since 1.0
		 *
		 * @param array $column Set of columns on page
		 * @return array $column Modified column list
		 */
		public function npu_modify_user_table( $column ) {
			$column['recEmailNotification'] = 'Emails <input type="checkbox" class="npuCheckAll" name="npuCheckAll" />';
			return $column;
		}
		
		/**
		 * Adds the AJAX checkbox control for individual users
		 *
		 * Displays on /wp-admin/users.php
		 * 
		 * Includes a loading gif span for visual aid 
		 *
		 * @since 1.0
		 *
		 * @param int $val (unused)
		 * @param string $column_name Specifies active column
		 * @param int $user_id Specifies user row
		 */
		public function npu_modify_user_table_row( $val, $column_name, $user_id ) {
		 	
		 	$recEmails = get_user_meta( $user_id, 'recEmailNotification', true );
			
			if($recEmails){
				$checked = 'checked="checked"';
				$msg = "User currently receives notification emails";
			} else{
				$checked = '';
				$msg = "User does not receive notification emails";
			}

			$output = '<input type="checkbox" class="recEmailNotification" '
				.'name="recEmailNotification-'.$user_id.'" '
				.'data-user-id="'.$user_id.'" title="'.$msg.'" '
				.'value="'.$user_id.'" '.$checked.' />';
			$output .= '<span class="npu-loading" style="display:none;'
				.'background:url(/wp-admin/images/wpspin_light.gif) 0 0 no-repeat; '
				.'width:16px; height:16px;float:right;margin-top:4px;"></span>';

			switch ($column_name) {
				case 'recEmailNotification' :
					return $output;
					break;
			}		 
		}
		
		/**
		 * Adds field to individual user profile field
		 *
		 * Grabs setting for checkbox and prints some HTML.
		 *
		 * @since 1.0
		 *
		 * @param obj $user User object
		 */
		public function npu_extra_profile_fields( $user ) {
			
			$recEmails = get_user_meta( $user->ID, 'recEmailNotification', true );
			$checked = '';
			if($recEmails) $checked = 'checked="checked"';

			echo '<h3>Email Notifications</h3>';
			echo '<table class="form-table">
					<tbody>
						<tr>
							<th><label for="recEmailNotification">Receive Email Notifications</label></th>
							<td>
								<input type="checkbox" id="recEmailNotification" value="" name="recEmailNotification" '.$checked.' />
								<label for="recEmailNotification">
									<span class="description">Check this if you wish to receive comment and notification emails.</span>
								</label>
							</td>
						</tr>
					</tbody>
				</table>';
		}

		/**
		 * Handles the profile page form submission
		 *
		 * Updates user meta to reflect checkbox value.
		 *
		 * @since 1.0
		 * @uses update_user_meta() Calls 'update_user_meta' to change notify value
		 *
		 * @param int $user_id User ID
		 */
		public function npu_update_user_meta( $user_id ) {

			$recEmails = 0;
			if(isset($_POST['recEmailNotification'])){
				$recEmails = 1;
			}

			update_user_meta( $user_id, 'recEmailNotification', $recEmails );
		}


		/**
		 * Inserts raw script into the page footer for use by jQuery.
		 *
		 * Handles 2 main functions - individual user checkbox click, and 
		 * the mass select/deselect boxes in the table header/footer.
		 *
		 * The select all functionality is a bit tricky - we are setting
		 * the .npuCheckAll elements opposite from the user checkboxes. On 
		 * mass select, user checkboxes are set to the opposite desired value
		 * and are then jQuery.click()ed to initiate the onChange event.
		 *
		 * @since 1.0
		 */
		public function npu_insert_ajax_status_script(){
			?>

			<script type="text/javascript">
				jQuery(document).ready(function($){
					
					// Single-checkbox click
					$('input.recEmailNotification').on('change', function(){
						
						var uid = $(this).data('user-id'),
							checked = $(this).attr('checked');

						if( typeof checked == 'undefined') checked = 0;
						else checked = 1;

						$(this).next('.npu-loading').fadeIn(100);

						// Make the call...
						jQuery.getJSON(ajaxurl,
							{   user_id: uid,
								action: 'npu_change_status',
								checked: checked,
							},
							function(data) {
								//console.log(data);
							}
						); // getJSON
						$(this).next('.npu-loading').fadeOut();

					}); // on change

					// Select / Deselect all - This is a bit tricky.
					$('input.npuCheckAll').on('change', function(){
						if( typeof $(this).attr('checked') == 'undefined'){
							$('input.recEmailNotification').attr( 'checked', 'checked' ).click();
							$('input.npuCheckAll').not(this).removeAttr( 'checked' );
						} else {
							$('input.recEmailNotification').removeAttr( 'checked' ).click();
							$('input.npuCheckAll').not(this).attr( 'checked', 'checked' );
						}
						
					}); // checkall

				});

			</script>
			<?php
		}

		/**
		 * Performs AJAX validation and meta update
		 *
		 * Returns a bit of usable JS console data.
		 *
		 * @since 1.0
		 *
		 */
		public function npu_ajax_change_status(){

			if (!isset($_GET['user_id'])){
				$re['data'] = 'NPU: No user id present.';
			} else{
				update_user_meta( $_GET['user_id'], 'recEmailNotification', $_GET['checked'] );
				$re['data'] = 'NPU: User '.$_GET['uid'].' updated to '.$_GET['checked'];
			}			

			echo json_encode($re);
			die();
			exit;
		}

	} // notificationsPerUser
} // if !class_exists

new notificationsPerUser(); // Ready for takeoff.