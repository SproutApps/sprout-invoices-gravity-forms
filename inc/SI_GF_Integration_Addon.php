<?php

GFForms::include_feed_addon_framework();

class SI_GF_Integration_Addon extends GFFeedAddOn {

	protected $_version = SI_GF_INTEGRATION_ADDON_VERSION;
	protected $_min_gravityforms_version = '1.9.16';
	protected $_slug = 'sprout-invoices-gravity-forms-integration';
	protected $_path = 'sprout-invoices-gravity-forms-integration/sprout-invoices-gravity-forms-integration.php';
	protected $_full_path = __FILE__;
	protected $_title = 'Sprout Invoices + Gravity Forms';
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

		if ( self::is_pd_items_supported() ) {
			add_filter( 'gform_pre_render', array( __CLASS__, 'populate_gf_choice_fields' ), 9 );
			add_filter( 'gform_pre_submission_filter', array( __CLASS__, 'populate_gf_choice_fields' ), 9 );
			add_filter( 'gform_pre_validation', array( __CLASS__, 'populate_gf_choice_fields' ), 9 );
		}

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
		$redirect = ( isset( $feed['meta']['redirect'] ) && $feed['meta']['redirect'] ) ? true : false ;
		$field_map = $this->get_field_map_fields( $feed, 'si_fields' );

		// Loop through the fields from the field map setting building an array of values to be passed to the third-party service.
		$submission = array();
		foreach ( $field_map as $name => $field_id ) {

			// Get the field value for the specified field id
			$submission[ $name ] = $this->get_field_value( $form, $entry, $field_id );

		}

		if ( isset( $field_map['address'] ) ) {
			$addy_field_id = (int) $field_map['address'];
			$state = str_replace( '  ', ' ', trim( rgar( $entry, $addy_field_id . '.4' ) ) );
			$state_code = GF_Fields::get( 'address' )->get_us_state_code( $state );
			$country = str_replace( '  ', ' ', trim( rgar( $entry, $addy_field_id . '.6' ) ) );
			$country_code = GF_Fields::get( 'address' )->get_country_code( $country );
			$submission['full_address'] = array(
					'street' => str_replace( '  ', ' ', trim( rgar( $entry, $addy_field_id . '.1' ) ) ) . ' ' . str_replace( '  ', ' ', trim( rgar( $entry, $addy_field_id . '.2' ) ) ),
					'city' => str_replace( '  ', ' ', trim( rgar( $entry, $addy_field_id . '.3' ) ) ),
					'zone' => $state_code,
					'postal_code' => str_replace( '  ', ' ', trim( rgar( $entry, $addy_field_id . '.5' ) ) ),
					'country' => $country_code,
				);
		}

		$submission['line_items'] = $this->get_line_items( $feed, $entry, $form );

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
				$invoice = SI_Invoice::get_instance( $invoice_id );
				$invoice->set_pending();
				$url = get_permalink( $invoice_id );
			} elseif ( get_post_type( $estimate_id ) == SI_Estimate::POST_TYPE ) {
				$estimate = SI_Estimate::get_instance( $estimate_id );
				$estimate->set_pending();
				$url = get_permalink( $estimate_id );
			}

			if ( isset( $url ) ) {
				if ( headers_sent() ) {
					$confirmation = self::gf_js_redirect( $url, $ajax );
					return $confirmation;
				} else {
					wp_redirect( $url );
					exit();
				}
			}
		}
	}

	private static function gf_js_redirect( $url, $ajax ) {
		$url = esc_url_raw( $url );
		$confirmation = '<script type="text/javascript">' . apply_filters( 'gform_cdata_open', '' ) . " function gformRedirect(){document.location.href='$url';}";
		if ( ! $ajax ) {
			$confirmation .= 'gformRedirect();';
		}

		$confirmation .= apply_filters( 'gform_cdata_close', '' ) . '</script>';

		return $confirmation;
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
						),
					),
					array(
						'name'     => 'line_items',
						'type'     => 'select',
						'choices'  => $this->get_line_items_field_choices(),
						'label'    => __( 'Line Items', 'sprout-invoices' ),
						'required' => 0,
						'tooltip'  => __( 'Line items can be created from the product and options the user selects or a checkbox field populated with the Sprout Invoices Pre-defined line items.', 'sprout-invoices' ),
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

		do_action( 'si_doc_generation_start' );

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
		do_action( 'si_invoice_submitted_from_adv_form', $invoice, $invoice_args );

		$invoice->set_line_items( $submission['line_items'] );
		$invoice->reset_totals();

		$invoice->set_calculated_total();

		// notes
		if ( ! empty( $submission['notes'] ) ) {
			$record_id = SI_Internal_Records::new_record( $submission['notes'], SI_Controller::PRIVATE_NOTES_TYPE, $invoice_id, '', 0, false );
		}

		if ( ! empty( $submission['number'] ) ) {
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

		do_action( 'si_gravity_forms_integration_invoice_created', $invoice, $submission, $entry, $form );

		do_action( 'si_doc_generation_complete', $invoice );

		return $invoice_id;

	}

	protected function create_estimate( $submission = array(), $entry = array(), $form = array() ) {

		do_action( 'si_doc_generation_start' );

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
		do_action( 'si_estimate_submitted_from_adv_form', $estimate, $estimate_args );

		$estimate->set_line_items( $submission['line_items'] );
		$estimate->reset_totals();

		$estimate->set_calculated_total();

		// notes
		if ( ! empty( $submission['notes'] ) ) {
			$record_id = SI_Internal_Records::new_record( $submission['notes'], SI_Controller::PRIVATE_NOTES_TYPE, $estimate_id, '', 0, false );
		}

		if ( ! empty( $submission['number'] ) ) {
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

		do_action( 'si_gravity_forms_integration_estimate_created', $estimate, $submission, $entry, $form );

		do_action( 'si_doc_generation_complete', $estimate );

		return $estimate_id;
	}

	protected function create_client( $submission = array(), $entry = array(), $form = array(), $doc_id = 0 ) {

		$email = $submission['email'];
		$client_name = $submission['client_name'];
		$first_name = $submission['user_first_name'];
		$last_name = $submission['user_last_name'];

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
					'first_name' => $first_name,
					'last_name' => $last_name,
					'user_url' => '',
				);
				$user_id = SI_Clients::create_user( $user_args );
			}
		}

		// check if client exists based on user submission
		$client = false;
		if ( $user_id ) {
			$client_ids = SI_Client::get_clients_by_user( $user_id );
			$clnt = ( ! empty( $client_ids ) ) ? SI_Client::get_instance( $client_ids[0] ) : 0 ;
			if ( is_a( $clnt, 'SI_Client' ) ) {
				$client = $clnt;
			}
		}

		// if already exists based on the user submission
		if ( $client ) {
			$client->set_title( $submission['client_name'] );
			$client->set_address( $submission['full_address'] );

		} else {
			// Make up the args in creating a client
			$args = array(
				'company_name' => $submission['client_name'],
				'website' => '',
				'address' => $submission['full_address'],
				'user_id' => $user_id,
			);
			$client_id = SI_Client::new_client( $args );
			$client = SI_Client::get_instance( $client_id );
		}

		if ( isset( $submission['vat'] ) ) {
			$client->save_post_meta( array( '_iva' => $submission['vat'] ) );
			$client->save_post_meta( array( '_vat' => $submission['vat'] ) );
		}

		do_action( 'si_gravity_forms_integration_client_created', $client, $submission, $entry, $form, $doc_id );

		if ( ! $doc_id ) {
			return;
		}

		/**
		 * After a client is created assign it to the estimate
		 */
		$doc = si_get_doc_object( $doc_id );
		$doc->set_client_id( $client->get_id() );

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

				$line_items_setting = rgar( $feed['meta'], 'line_items' );

				if ( empty( $line_items_setting ) ) {
					continue;
				}

				list( $type, $field_id ) = explode( '_', $line_items_setting );
				if ( $type === 'checkbox' ) {
					return $field_id;
				}
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

	public static function is_pd_items_supported() {
		return class_exists( 'Predefined_Items' );
	}

	/**
	 * Get an array of choices to be available for selection in the Line Items setting.
	 *
	 * @return array
	 */
	public function get_line_items_field_choices() {
		$form = $this->get_current_form();

		$choices         = array();
		$product_fields  = array();
		$checkbox_fields = array();

		$pd_line_items_supported = self::is_pd_items_supported();

		/** @var GF_Field $field The field object. */
		foreach ( $form['fields'] as $field ) {
			if ( $field->type === 'product' ) {
				$product_fields[] = array(
					'value' => 'product_' . $field->id,
					'label' => GFCommon::get_label( $field ),
				);
				continue;
			}

			if ( $pd_line_items_supported && $field->get_input_type() === 'checkbox' ) {
				$checkbox_fields[] = array(
					'value' => 'checkbox_' . $field->id,
					'label' => GFCommon::get_label( $field ),
				);
			}
		}

		if ( ! empty( $product_fields ) ) {
			$product_fields[] = array(
				'value' => 'all_products',
				'label' => __( 'All Pricing Fields', 'sprout-invoices' ),
			);
		}

		// Add no value
		$choices[] = array(
			'label'   => __( 'None', 'sprout-invoices' ),
			'value' => '',
		);

		// Add the optgroup for the product fields.
		$choices[] = array(
			'label'   => __( 'Product Fields', 'sprout-invoices' ),
			'choices' => $product_fields,
		);

		if ( $pd_line_items_supported ) {
			// Add the optgroup for the checkbox fields.
			$choices[] = array(
				'label'   => __( 'SI Pre-defined Line Items (Checkbox Fields)', 'sprout-invoices' ),
				'choices' => $checkbox_fields,
			);
		}

		return $choices;
	}

	/**
	 * Get the line items for the current entry based on the current feed and form configuration.
	 *
	 * @param array $feed The current feed.
	 * @param array $entry The current entry.
	 * @param array $form  The current form.
	 *
	 * @return array
	 */
	public function get_line_items( $feed, $entry, $form ) {
		$product_type       = rgar( $feed['meta'], 'product_type' );
		$line_items_setting = rgar( $feed['meta'], 'line_items' );
		$line_items         = array();

		if ( $line_items_setting === 'all_products' ) {
			$products = GFCommon::get_product_fields( $form, $entry );

			if ( empty( $products['products'] ) ) {
				return $line_items;
			}

			foreach ( $products['products'] as $product ) {
				$line_items[] = $this->get_line_item_from_product( $product, $entry, $product_type );
			}

			if ( ! empty( $products['shipping']['name'] ) ) {
				$line_items[] = $this->get_line_item_from_product( $products['shipping'], $entry, $product_type );
			}
		} elseif ( ! empty( $line_items_setting ) ) {
			list( $type, $field_id ) = explode( '_', $line_items_setting );

			if ( $type === 'product' ) {
				$products = GFCommon::get_product_fields( $form, $entry );
				$product  = rgar( $products['products'], $field_id );

				if ( $product ) {
					$line_items[] = $this->get_line_item_from_product( $product, $entry, $product_type );
				}
			} elseif ( $type === 'checkbox' && self::is_pd_items_supported() ) {
				$number_of_choices = count( self::line_item_choices( $field_id ) );
				for ( $i = 1; $i < $number_of_choices + 1; $i ++ ) {
					$item_id = rgar( $entry, $field_id . '.' . $i );
					$item    = SI_Item::get_instance( $item_id );
					if ( ! is_a( $item, 'SI_Item' ) ) {
						continue;
					}
					$subtotal = $item->get_default_rate() * $item->get_default_qty();
					$tax_total = $subtotal * ( $item->get_default_percentage() / 100 );
					$line_items[] = array(
						'rate'  => $item->get_default_rate(),
						'qty'   => $item->get_default_qty(),
						'tax'   => $item->get_default_percentage(),
						'total' => ( $subtotal - $tax_total ),
						'desc'  => $item->get_content(),
					);
				}
			}
		}

		return $line_items;
	}

	/**
	 * Get the line item array for a single product.
	 *
	 * @param array  $product      The product properties.
	 * @param array  $entry        The current entry.
	 * @param string $product_type The Sprout Invoices product type.
	 *
	 * @return array
	 */
	public function get_line_item_from_product( $product, $entry, $product_type ) {
		$options = array();
		if ( is_array( rgar( $product, 'options' ) ) ) {
			foreach ( $product['options'] as $option ) {
				$options[] = $option['option_name'];
			}
		}

		if ( ! empty( $options ) ) {
			$description = sprintf( esc_html__( '%s; options: %s', 'sprout-invoices' ), rgar( $product, 'name' ), implode( ', ', $options ) );
		} else {
			$description = rgar( $product, 'name', '' );
		}

		$rate     = GFCommon::to_number( rgar( $product, 'price', 0 ), $entry['currency'] );
		$quantity = GFCommon::to_number( rgar( $product, 'quantity', 1 ), $entry['currency'] );

		return array(
			'type'  => $product_type,
			'desc'  => wp_kses_post( apply_filters( 'si_gf_line_item_description', $description, $product, $options ) ),
			'rate'  => $rate,
			'qty'   => $quantity,
			'total' => $rate * $quantity,
			'tax'   => apply_filters( 'si_form_submission_line_item_default_tax', 0.00 ),
		);
	}

	/**
	 * Performs installation or upgrade tasks.
	 *
	 * @param string $previous_version The previously installed version number.
	 */
	public function upgrade( $previous_version ) {
		if ( ! empty( $previous_version ) && version_compare( $previous_version, '1.0.2', '<' ) ) {
			$this->upgrade_102();
		}
	}

	/**
	 * Upgrade existing feeds using the si_fields_products and si_fields_pd_line_items settings to the new line_items setting.
	 */
	public function upgrade_102() {
		$feeds = $this->get_feeds();

		foreach ( $feeds as $feed ) {
			$feed_dirty = false;
			$feed_meta  = $feed['meta'];

			$si_fields_products = rgar( $feed_meta, 'si_fields_products' );
			if ( $si_fields_products ) {
				$feed_meta['line_items'] = 'product_' . absint( $si_fields_products );
				$feed_dirty              = true;
			}

			$si_fields_pd_line_items = rgar( $feed_meta, 'si_fields_pd_line_items' );
			if ( $si_fields_pd_line_items ) {
				$feed_meta['line_items'] = 'checkbox_' . absint( $si_fields_pd_line_items );
				$feed_dirty              = true;
			}

			if ( $feed_dirty ) {
				$this->update_feed_meta( $feed['id'], $feed_meta );
			}
		}
	}
}
