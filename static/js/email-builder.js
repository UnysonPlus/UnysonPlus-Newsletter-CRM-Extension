/**
 * Email Builder — client-side block registration.
 *
 * The framework's builder needs every item type registered in JS as well as PHP,
 * or the canvas logs "Cannot detect Item type" and renders nothing for a saved
 * tree. The Learning extension's quiz builder does this with one near-identical
 * script per item; because our blocks differ only in their label, icon and
 * options schema, ONE generic registration driven by localized data does the job
 * and leaves nothing to keep in sync four times over.
 */
/* global fwEvents, fw, _, fwCrmEmailBuilder */
fwEvents.one( 'fw-builder:email-builder:register-items', function ( builder ) {
	if ( typeof fwCrmEmailBuilder === 'undefined' ) {
		return;
	}

	var l10n = fwCrmEmailBuilder.l10n || {};

	_.each( fwCrmEmailBuilder.types, function ( config, type ) {

		var ItemView = builder.classes.ItemView.extend( {
			template: _.template(
				'<div class="fw-crm-eb-item fw-crm-eb-item--<%- type %>">' +
					'<div class="fw-crm-eb-item__bar">' +
						'<span class="fw-crm-eb-item__icon"><%= icon %></span>' +
						'<span class="fw-crm-eb-item__title"><%- title %></span>' +
						'<span class="fw-crm-eb-item__actions">' +
							'<a class="fw-crm-eb-edit dashicons dashicons-edit" href="#" onclick="return false;" data-hover-tip="<%- edit %>"></a>' +
							'<a class="fw-crm-eb-remove dashicons dashicons-no" href="#" onclick="return false;" data-hover-tip="<%- remove %>"></a>' +
						'</span>' +
					'</div>' +
					'<div class="fw-crm-eb-item__preview"><%- preview %></div>' +
				'</div>'
			),

			events: {
				'click .fw-crm-eb-edit': 'openEdit',
				'click .fw-crm-eb-item__preview': 'openEdit',
				'click .fw-crm-eb-remove': 'removeItem'
			},

			initialize: function () {
				this.defaultInitialize();

				this.modal = new fw.OptionsModal( {
					title: config.title,
					options: this.model.modalOptions,
					values: this.model.get( 'options' ),
					size: 'medium'
				} );

				this.listenTo( this.modal, 'change:values', function ( modal, values ) {
					this.model.set( 'options', values );
				} );

				this.model.on( 'change:options', function () {
					this.modal.set( 'values', this.model.get( 'options' ) );
					this.render();
				}, this );
			},

			render: function () {
				this.defaultRender( {
					type: type,
					title: config.title,
					icon: config.icon,
					preview: this.previewText(),
					edit: l10n.edit || 'Edit',
					remove: l10n.remove || 'Remove'
				} );
			},

			/**
			 * A one-line summary of the block, so the canvas is readable without
			 * opening every modal.
			 */
			previewText: function () {
				var o = this.model.get( 'options' ) || {};
				var text = '';

				switch ( type ) {
					case 'text':
						text = String( o.content || '' ).replace( /<[^>]*>/g, ' ' ).replace( /\s+/g, ' ' ).trim();
						break;
					case 'button':
						text = String( o.label || '' );
						if ( o.url ) { text += ' → ' + o.url; }
						break;
					case 'image':
						text = String( o.alt || '' ) || ( o.image && o.image.url ? o.image.url : '' );
						break;
					case 'divider':
						text = o.style === 'space' ? ( l10n.space || 'Space' ) : ( l10n.line || 'Line' );
						break;
				}

				text = text || ( l10n.empty || 'Not set yet' );

				return text.length > 90 ? text.substring( 0, 90 ) + '…' : text;
			},

			openEdit: function () {
				this.modal.open();
				return false;
			},

			removeItem: function () {
				this.remove();
				this.model.collection.remove( this.model );
				return false;
			}
		} );

		var Item = builder.classes.Item.extend( {
			defaults: function () {
				return _.clone( config.defaults );
			},

			initialize: function () {
				this.defaultInitialize();

				this.modalOptions = config.options;

				this.view = new ItemView( {
					id: 'fw-builder-item-' + this.cid,
					model: this
				} );
			}
		} );

		builder.registerItemClass( Item );
	} );
} );
