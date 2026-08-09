<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Settings for the Newsletter / Subscriber CRM extension.
 *
 * Rendered both on the extension's own Settings tab (Unyson+ → Subscribers) and
 * by the Extensions manager's settings form, so it must stay a plain options
 * array — no side effects, and safe to load on the front end (the options model
 * resolves defaults there too).
 */

$list_choices = array( '' => __( '— Default —', 'fw' ) );

// The lists table may not exist yet on the very first load after activation.
if ( class_exists( 'FW_Newsletter_CRM_Lists' ) && get_option( 'fw_ext_newsletter_crm_db_version' ) ) {
	foreach ( FW_Newsletter_CRM_Lists::all( 'list' ) as $list ) {
		$list_choices[ $list->slug ] = $list->title;
	}
}

$options = array(
	'box_capture' => array(
		'type'    => 'box',
		'title'   => __( 'Capture', 'fw' ),
		'options' => array(
			'default_list' => array(
				'type'    => 'select',
				'label'   => __( 'Default list', 'fw' ),
				'desc'    => __( 'Where a signup lands when the [newsletter] element has no List ID of its own.', 'fw' ),
				'value'   => '',
				'choices' => $list_choices,
			),
			'auto_create_lists' => array(
				'type'         => 'switch',
				'label'        => __( 'Create lists on demand', 'fw' ),
				'desc'         => __( 'The element\'s List ID is free text. With this on, an unrecognised ID creates the list automatically; with it off, the signup falls back to the default list.', 'fw' ),
				'value'        => 'yes',
				'right-choice' => array( 'value' => 'yes', 'label' => __( 'Yes', 'fw' ) ),
				'left-choice'  => array( 'value' => 'no', 'label' => __( 'No', 'fw' ) ),
			),
			'admin_notify' => array(
				'type'         => 'switch',
				'label'        => __( 'Email the admin on every signup', 'fw' ),
				'desc'         => __( 'The [newsletter] element notifies the site admin by email. Now that signups are stored, you may not want that any more — turn it off here.', 'fw' ),
				'value'        => 'yes',
				'right-choice' => array( 'value' => 'yes', 'label' => __( 'Yes', 'fw' ) ),
				'left-choice'  => array( 'value' => 'no', 'label' => __( 'No', 'fw' ) ),
			),
			'double_optin' => array(
				'type'         => 'switch',
				'label'        => __( 'Double opt-in', 'fw' ),
				'desc'         => __( 'Store new signups as Pending until they confirm. The confirmation email and the public confirm link are not built yet, so leaving this ON means subscribers sit at Pending until you confirm them — it is here so the data is recorded correctly from day one.', 'fw' ),
				'value'        => 'no',
				'right-choice' => array( 'value' => 'yes', 'label' => __( 'Yes', 'fw' ) ),
				'left-choice'  => array( 'value' => 'no', 'label' => __( 'No', 'fw' ) ),
			),
		),
	),

	'box_privacy' => array(
		'type'    => 'box',
		'title'   => __( 'Privacy', 'fw' ),
		'options' => array(
			'store_ip' => array(
				'type'         => 'switch',
				'label'        => __( 'Store the signup IP', 'fw' ),
				'desc'         => __( 'Kept as consent evidence — who agreed, when, and from where. It is personal data, so it is included in the WordPress personal-data export and cleared on erasure.', 'fw' ),
				'value'        => 'yes',
				'right-choice' => array( 'value' => 'yes', 'label' => __( 'Yes', 'fw' ) ),
				'left-choice'  => array( 'value' => 'no', 'label' => __( 'No', 'fw' ) ),
			),
			'anonymize_ip' => array(
				'type'         => 'switch',
				'label'        => __( 'Anonymise the IP', 'fw' ),
				'desc'         => __( 'Masks the last octet before storing, using WordPress\'s own anonymiser.', 'fw' ),
				'value'        => 'no',
				'right-choice' => array( 'value' => 'yes', 'label' => __( 'Yes', 'fw' ) ),
				'left-choice'  => array( 'value' => 'no', 'label' => __( 'No', 'fw' ) ),
			),
		),
	),
);
