<?php

GFForms::include_feed_addon_framework();

class SI_GF_Integration_Addon extends GFFeedAddOn {

	protected $_version = SI_GF_INTEGRATION_ADDON_VERSION;
	protected $_min_gravityforms_version = '1.9.16';
	protected $_slug = 'sprout-invoices-gravity-forms-integration';
	protected $_path = 'sprout-invoices-gravity-forms-integration/sprout-invoices-gravity-forms-integration.php';
	protected $_full_path = __FILE__;
	protected $_title = 'Gravity Forms Simple Add-On';
	protected $_short_title = 'Sprout Invoices';

	private static $_instance = null;

	private static $_pd_form_map_id = 0;

	public static function get_instance() {
		if ( self::$_instance == null ) {
			self::$_instance = new SI_GF_Integration_Addon();
		}

		return self::$_instance;
	}

	public function init() {
		parent::init();

		add_filter( 'gform_pre_render', array( __CLASS__, 'populate_gf_choice_fields' ), 9 );
		add_filter( 'gform_pre_submission_filter', array( __CLASS__, 'populate_gf_choice_fields' ), 9 );
		add_filter( 'gform_pre_validation', array( __CLASS__, 'populate_gf_choice_fields' ), 9 );

	}

	/**
	 * Process the feed e.g. subscribe the user to a list.
	 *
	 * @param array $feed The feed object to be processed.
	 * @param array $entry The entry object currently being processed.
	 * @param array $form The form object currently being processed.
	 *
	 * @return bool|void
	 */
	public function process_feed( $feed, $entry, $form ) {

		do_action( 'si_log', __CLASS__ . '::' . __FUNCTION__ . ' - feed', $feed, false );
		do_action( 'si_log', __CLASS__ . '::' . __FUNCTION__ . ' - entry', $entry, false );
		$generate  = $feed['meta']['si_generation'];
		$product_type  = $feed['meta']['product_type'];
		$redirect  = (bool) $feed['meta']['redirect'];
		$field_map = $this->get_field_map_fields( $feed, 'si_fields' );

		// Loop through the fields from the field map setting building an array of values to be passed to the third-party service.
		$submission = array();
		foreach ( $field_map as $name => $field_id ) {

			// Get the field value for the specified field id
			$submission[ $name ] = $this->get_field_value( $form, $entry, $field_id );

		}

		if ( isset( $field_map['address'] ) ) {
			$addy_field_id = (int) $field_map['address'];
			$submission['full_address'] = array(
					'street' => str_replace( '  ', ' ', trim( rgar( $entry, $addy_field_id . '.1' ) ) ) . ' ' . str_replace( '  ', ' ', trim( rgar( $entry, $addy_field_id . '.2' ) ) ),
					'city' => str_replace( '  ', ' ', trim( rgar( $entry, $addy_field_id . '.3' ) ) ),
					'zone' => str_replace( '  ', ' ', trim( rgar( $entry, $addy_field_id . '.4' ) ) ),
					'postal_code' => str_replace( '  ', ' ', trim( rgar( $entry, $addy_field_id . '.5' ) ) ),
					'country' => str_replace( '  ', ' ', trim( rgar( $entry, $addy_field_id . '.6' ) ) ),
				);
		}

		$line_items = array();
		if ( isset( $field_map['products'] ) ) {
			$entry_id = $field_map['products'];
			if ( isset( $entry[ $entry_id ] ) ) {
				$line_item = explode( '|', $entry[ $entry_id ] );
				if ( is_array( $line_item ) && strlen( (string) $line_item[0] ) > 0 ) {
					$line_items[] = array(
						'type' => $product_type,
						'desc' => $line_item[0],
						'rate' => $line_item[1],
						'total' => $line_item[1],
						'qty' => 1,
						'tax' => apply_filters( 'si_form_submission_line_item_default_tax', 0.00 ),
					);
				}
			}
		}

		if ( isset( $field_map['pd_line_items'] ) ) {
			$number_of_choices = count( self::line_item_choices( $field_map['pd_line_items'] ) );
			for ( $i = 1; $i < $number_of_choices + 1; $i++ ) {
				$item_id = ( isset( $entry[ $field_map['pd_line_items'] . '.' . $i ] ) ) ? $entry[ $field_map['pd_line_items'] . '.' . $i ] : '' ;
				$item = SI_Item::get_instance( $item_id );
				if ( ! is_a( $item, 'SI_Item' ) ) {
					continue;
				}
				$line_items[] = array(
					'rate' => $item->get_default_rate(),
					'qty' => $item->get_default_qty(),
					'tax' => $item->get_default_percentage(),
					'total' => ($item->get_default_rate() * $item->get_default_qty()),
					'desc' => $item->get_content(),
				);
			}
		}

		$submission['line_items'] = $line_items;

		do_action( 'si_log', __CLASS__ . '::' . __FUNCTION__ . ' - submission', $submission, false );

		switch ( $generate ) {
			case 'invoice':
				$invoice_id = $this->create_invoice( $submission, $entry, $form );
				$this->create_client( $submission, $entry, $form, $invoice_id );
				break;
			case 'estimate':
				$estimate_id = $this->create_estimate( $submission, $entry, $form );
				$this->create_client( $submission, $entry, $form, $estimate_id );
				break;
			case 'client':
				$this->create_client( $submission, $entry, $form );
				break;
			default:
				// nada
				break;
		}

		if ( $redirect && ( isset( $invoice_id ) || isset( $estimate_id ) ) ) {
			if ( get_post_type( $invoice_id ) == SI_Invoice::POST_TYPE ) {
				$url = get_permalink( $invoice_id );
			} elseif ( get_post_type( $estimate_id ) == SI_Estimate::POST_TYPE ) {
				$url = get_permalink( $estimate_id );
			}

			if ( isset( $url ) ) {
				if ( headers_sent() ) {
					$confirmation = GFFormDisplay::get_js_redirect_confirmation( $url, $ajax );
				} else {
					wp_redirect( $url );
					exit();
				}
			}
		}
	}

	public function feed_settings_fields() {

		$li_types = SI_Line_Items::line_item_types();
		$line_item_types = array();
		foreach ( $li_types as $value => $label ) {
			$line_item_types[] = array(
				'label' => $label,
				'name' => $label,
				'value' => $value,
			);
		}

		return array(
			array(
				'title'  => esc_html__( 'Sprout Invoices Integration Options', 'sprout-invoices' ),
				'fields' => array(
					array(
						'label'   => esc_html__( 'Generation', 'sprout-invoices' ),
						'type'    => 'radio',
						'name'    => 'si_generation',
						'tooltip' => esc_html__( 'After a form is successfully submitted these are the records created.', 'sprout-invoices' ),
						'choices' => array(
							array(
								'label' => esc_html__( 'Estimate (and Client Record)', 'sprout-invoices' ),
								'name'  => 'estimate',
								'value' => 'estimate',
							),
							array(
								'label' => esc_html__( 'Invoice (and Client Record)', 'sprout-invoices' ),
								'name'  => 'invoice',
								'value' => 'invoice',
							),
							array(
								'label' => esc_html__( 'Client (only)', 'sprout-invoices' ),
								'name'  => 'client',
								'value' => 'client',
							),
						),
						'onchange' => 'jQuery(this).parents("form").submit();',
					),

					array(
						'label'     => __( 'Integration Mapping', 'sprout-invoices' ),
						'type'      => 'field_map',
						'name'      => 'si_fields',
						'dependency' => 'si_generation',
						'tooltip' => esc_html__( 'Map the fields for your form here so Sprout Invoices can use the submitted information.', 'sprout-invoices' ),
						'field_map' => array(
							array(
								'name'     => 'subject',
								'label'    => __( 'Invoice/Estimate Title', 'sprout-invoices' ),
								'required' => 0,
							),
							array(
								'name'     => 'client_name',
								'label'    => __( 'Company Name', 'sprout-invoices' ),
								'required' => 1,
							),
							array(
								'name'     => 'user_first_name',
								'label'    => __( 'User First Name', 'sprout-invoices' ),
								'required' => 0,
							),
							array(
								'name'     => 'user_last_name',
								'label'    => __( 'User Last Name', 'sprout-invoices' ),
								'required' => 0,
							),
							array(
								'name'     => 'email',
								'label'    => __( 'Email', 'sprout-invoices' ),
								'required' => 1,
							),
							array(
								'name'     => 'address',
								'label'    => __( 'Address', 'sprout-invoices' ),
								'required' => 0,
								'field_type' => array( 'address' ),
							),
							array(
								'name'     => 'notes',
								'label'    => __( 'Notes', 'sprout-invoices' ),
								'required' => 0,
							),
							array(
								'name'       => 'number',
								'label'      => __( 'Estimate/Invoice Number', 'sprout-invoices' ),
								'required'   => 0,
							),
							array(
								'name'       => 'vat',
								'label'      => __( 'VAT Number', 'sprout-invoices' ),
								'required'   => 0,
							),
							array(
								'name'     => 'products',
								'label'    => __( 'Line Items', 'sprout-invoices' ),
								'required' => 0,
								'tooltip'  => __( 'Line items will be created from the products the user selects. How-to: add a products field (and add all products and prices), then select it here.', 'sprout-invoices' ),
							),
							array(
								'name'     => 'pd_line_items',
								'label'    => __( 'SI Pre-defined Line Items', 'sprout-invoices' ),
								'required' => 0,
								'tooltip'  => __( 'Instead of using products this will modify a "checkboxes" field to show Sprout Invoices line items. Hot-to: add a blank "checkboxes" field to your form and select it here.', 'sprout-invoices' ),
							),
						),
					),
					array(
						'name'       => 'product_type',
						'label'    => __( 'Line Item Type for Products', 'sprout-invoices' ),
						'type'       => 'select',
						'horizontal' => true,
						'tooltip'  => __( 'Ignore this if you are not using products to build line items.', 'sprout-invoices' ),
						'choices'    => $line_item_types,
					),
					array(
						'name'       => 'redirect',
						'label'    => __( 'Redirect', 'sprout-invoices' ),
						'type'       => 'checkbox',
						'horizontal' => true,
						'tooltip'  => __( 'Redirects the form submitter to the invoice or estimate created.', 'sprout-invoices' ),
						'choices' => array(
								array(
									'label' => esc_html__( 'Enabled', 'sprout-invoices' ),
									'name'  => 'redirect',
								),
							),
						),
					),
				),
			);
	}

	protected function create_invoice( $submission = array(), $entry = array(), $form = array() ) {

		$invoice_args = array(
			'subject' => sprintf( apply_filters( 'si_form_submission_title_format', '%1$s (%2$s)', $submission ), $submission['subject'], $submission['client_name'] ),
			'fields' => $submission,
			'form' => $form,
			'history_link' => sprintf( '<a href="%s">#%s</a>', add_query_arg( array( 'id' => $entry['form_id'], 'lid' => $entry['id'] ), admin_url( 'admin.php?page=gf_entries&view=entry' ) ), $entry['id'] ),
		);
		/**
		 * Creates the invoice from the arguments
		 */
		$invoice_id = SI_Invoice::create_invoice( $invoice_args );
		$invoice = SI_Invoice::get_instance( $invoice_id );

		$invoice->set_line_items( $submission['line_items'] );

		// notes
		if ( isset( $submission['notes'] ) ) {
			$record_id = SI_Internal_Records::new_record( $submission['notes'], SI_Controller::PRIVATE_NOTES_TYPE, $invoice_id, '', 0, false );
		}

		if ( isset( $submission['number'] ) ) {
			$invoice->set_invoice_id( $submission['number'] );
		}

		// Finally associate the doc with the form submission
		add_post_meta( $invoice_id, 'gf_form_id', $entry['id'] );

		$history_link = sprintf( '<a href="%s">#%s</a>', add_query_arg( array( 'id' => $entry['form_id'], 'lid' => $entry['id'] ), admin_url( 'admin.php?page=gf_entries&view=entry' ) ), $entry['id'] );

		do_action( 'si_new_record',
			sprintf( __( 'Invoice Submitted: Form %s.', 'sprout-invoices' ), $history_link ),
			'invoice_submission',
			$invoice_id,
			sprintf( __( 'Invoice Submitted: Form %s.', 'sprout-invoices' ), $history_link ),
			0,
		false );

		return $invoice_id;

	}

	protected function create_estimate( $submission = array(), $entry = array(), $form = array() ) {

		$estimate_args = array(
			'subject' => sprintf( apply_filters( 'si_form_submission_title_format', '%1$s (%2$s)', $submission ), $submission['subject'], $submission['client_name'] ),
			'fields' => $submission,
			'form' => $form,
			'history_link' => sprintf( '<a href="%s">#%s</a>', add_query_arg( array( 'id' => $entry['form_id'], 'lid' => $entry['id'] ), admin_url( 'admin.php?page=gf_entries&view=entry' ) ), $entry['id'] ),
		);
		/**
		 * Creates the estimate from the arguments
		 */
		$estimate_id = SI_Estimate::create_estimate( $estimate_args );
		$estimate = SI_Estimate::get_instance( $estimate_id );

		$estimate->set_line_items( $submission['line_items'] );

		// notes
		if ( isset( $submission['notes'] ) ) {
			$record_id = SI_Internal_Records::new_record( $submission['notes'], SI_Controller::PRIVATE_NOTES_TYPE, $estimate_id, '', 0, false );
		}

		if ( isset( $submission['number'] ) ) {
			$estimate->set_estimate_id( $submission['number'] );
		}

		// Finally associate the doc with the form submission
		add_post_meta( $estimate_id, 'gf_form_id', $entry['id'] );

		$history_link = sprintf( '<a href="%s">#%s</a>', add_query_arg( array( 'id' => $entry['form_id'], 'lid' => $entry['id'] ), admin_url( 'admin.php?page=gf_entries&view=entry' ) ), $entry['id'] );

		do_action( 'si_new_record',
			sprintf( __( 'Estimate Submitted: Form %s.', 'sprout-invoices' ), $history_link ),
			'estimate_submission',
			$estimate_id,
			sprintf( __( 'Estimate Submitted: Form %s.', 'sprout-invoices' ), $history_link ),
			0,
		false );

		return $estimate_id;
	}

	protected function create_client( $submission = array(), $entry = array(), $form = array(), $doc_id = 0 ) {

		$email = $submission['email'];

		/**
		 * Attempt to create a user before creating a client.
		 */
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			if ( '' !== $email ) {
				// check to see if the user exists by email
				$user = get_user_by( 'email', $email );
				if ( $user ) {
					$user_id = $user->ID;
				}
			}
		}

		// Create a user for the submission if an email is provided.
		if ( ! $user_id ) {
			// email is critical
			if ( '' !== $email ) {
				$user_args = array(
					'user_login' => esc_attr__( $email ),
					'display_name' => isset( $client_name ) ? esc_attr__( $client_name ) : esc_attr__( $email ),
					'user_email' => esc_attr__( $email ),
					'first_name' => si_split_full_name( esc_attr__( $full_name ), 'first' ),
					'last_name' => si_split_full_name( esc_attr__( $full_name ), 'last' ),
					'user_url' => '',
				);
				$user_id = SI_Clients::create_user( $user_args );
			}
		}

		// Make up the args in creating a client
		$args = array(
			'company_name' => $submission['client_name'],
			'website' => '',
			'address' => $submission['full_address'],
			'user_id' => $user_id,
		);
		$client_id = SI_Client::new_client( $args );
		$client = SI_Client::get_instance( $client_id );

		if ( isset( $submission['vat'] ) ) {
			$client->save_post_meta( array( '_iva' => $submission['vat'] ) );
			$client->save_post_meta( array( '_vat' => $submission['vat'] ) );
		}

		if ( ! $doc_id ) {
			return;
		}

		/**
		 * After a client is created assign it to the estimate
		 */
		$doc = si_get_doc_object( $doc_id );
		$doc->set_client_id( $client_id );

	}


	/**
	 * Configures which columns should be displayed on the feed list page.
	 *
	 * @return array
	 */
	public function feed_list_columns() {
		return array(
			'name'  => esc_html__( 'Generation Type', 'sprout-invoices' ),
		);
	}

	/**
	 * Format the value to be displayed in the mytextbox column.
	 *
	 * @param array $feed The feed being included in the feed list.
	 *
	 * @return string
	 */
	public function get_column_value_name( $feed ) {
		return '<b>' . rgars( $feed, 'meta/si_generation' ) . '</b>';
	}


	//////////////////////////////
	// Populate Front-end Form //
	//////////////////////////////

	public static function populate_gf_choice_fields( $form ) {

		$feed_id_pre_line_items = self::get_pd_line_items_field_id( $form );

		if ( ! $feed_id_pre_line_items ) {
			return $form;
		}

		$line_item_choices = self::line_item_choices( $feed_id_pre_line_items );

		if ( ! $line_item_choices || empty( $line_item_choices ) ) {
			return $form;
		}

		foreach ( $form['fields'] as $key => $data ) {
			if ( $data['id'] == $feed_id_pre_line_items ) {
				$form['fields'][ $key ]['choices'] = $line_item_choices;
				$form['fields'][ $key ]['inputs'] = $line_item_choices;
			}
		}
		return $form;

	}

	public static function get_pd_line_items_field_id( $form = array() ) {
		$feeds = self::get_si_feeds( $form['id'] );
		if ( empty( $feeds ) ) {
			return false;
		}

		$id = 0;
		foreach ( $feeds as $feed ) {
			if ( isset( $feed['is_active'] ) && $feed['is_active'] ) {
				if ( ! isset( $feed['addon_slug'] ) || 'sprout-invoices-gravity-forms-integration' !== $feed['addon_slug'] ) {
					continue;
				}

				if ( ! isset( $feed['meta']['si_fields_pd_line_items'] ) || ! $feed['meta']['si_fields_pd_line_items'] ) {
					continue;
				}
				$id = $feed['meta']['si_fields_pd_line_items'];
			}
		}

		return $id;
	}

	protected static function line_item_choices( $pd_line_items_id = 0 ) {

		if ( ! $pd_line_items_id ) {
			return false;
		}

		$choices = array();
		$items_and_products = Predefined_Items::get_items_and_products();
		$item_groups = apply_filters( 'si_predefined_items_for_submission', $items_and_products );
		foreach ( $item_groups as $type => $items ) {
			$index = 0;
			foreach ( $items as $key ) {
				$index++;
				$choices[] = array(
						'id' => $pd_line_items_id . '.'.$index,
						'label' => $key['title'],
						'text' => sprintf( '<b>%s</b><br/><small>%s</small>', $key['title'], $key['content'] ),
						'value' => $key['id'],
						'price' => $key['rate'],
						'name' => '',
					);
			}
		}
		return $choices;
	}



	protected static function get_si_feeds( $form_id = null ) {
		global $wpdb;

		$form_filter = is_numeric( $form_id ) ? $wpdb->prepare( 'AND form_id=%d', absint( $form_id ) ) : '';

		$sql = $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}gf_addon_feed
                               WHERE addon_slug=%s {$form_filter} ORDER BY `feed_order`, `id` ASC", 'sprout-invoices-gravity-forms-integration' );

		$results = $wpdb->get_results( $sql, ARRAY_A );
		foreach ( $results as &$result ) {
			$result['meta'] = json_decode( $result['meta'], true );
		}

		return $results;
	}
}

