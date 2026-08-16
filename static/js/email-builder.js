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

	// types is an OBJECT — _.each yields (value, key).
	Object.keys( fwCrmEmailBuilder.types ).forEach( function ( type ) {
		var config = fwCrmEmailBuilder.types[ type ];

		var ItemView = builder.classes.ItemView.extend( {
			template: fw.template(
				'<div class="fw-crm-eb-item fw-crm-eb-item--<%- type %>">' +
					'<div class="fw-crm-eb-item__bar">' +
						'<span class="fw-crm-eb-item__icon"><%= icon %></span>' +
						'<span class="fw-crm-eb-item__title"><%- title %></span>' +
						'<span class="fw-crm-eb-item__width"></span>' +
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

				// The width changer is a FRAMEWORK component (the builder
				// extension's helpers.js), not something we or the Learning
				// extension invented. It writes to the model's `width` attribute,
				// which the compiler then packs into rows.
				if ( typeof FwBuilderComponents !== 'undefined' && FwBuilderComponents.ItemView.WidthChanger ) {
					this.widthChangerView = new FwBuilderComponents.ItemView.WidthChanger( {
						model: this.model,
						view: this
					} );
				}
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

				if ( this.widthChangerView ) {
					this.$( '.fw-crm-eb-item__width' ).append( this.widthChangerView.$el );
					this.widthChangerView.delegateEvents();
				}
			},

			/**
			 * A one-line summary of the block, so the canvas is readable without
			 * opening every modal.
			 */
			/**
			 * A one-line summary so the canvas is readable without opening every
			 * modal. Driven by the block's own `preview` keys from PHP, with a
			 * generic fallback — a switch here would need editing for every new
			 * block, which is exactly the coupling worth avoiding.
			 */
			previewText: function () {
				var o = this.model.get( 'options' ) || {};
				var keys = config.preview && config.preview.length
					? config.preview
					: Object.keys( o );
				var text = '';

				function readable( v ) {
					if ( v === null || typeof v === 'undefined' ) { return ''; }
					// A repeater stores an array of rows. Their labels say far more
					// than a count does, so list them and only fall back to the
					// count if the rows are unlabelled.
					if ( Array.isArray( v ) ) {
						if ( ! v.length ) { return ''; }

						// _.compact(_.map(...)) — map then drop falsy entries.
						var labels = v.map( function ( row ) {
							return row && ( row.label || row.title || row.text || row.url ) || '';
						} ).filter( Boolean );

						return labels.length ? labels.join( ', ' ) : v.length + '';
					}
					// An upload option stores { url: … }.
					if ( fw.isObject( v ) ) { return String( v.url || '' ); }
					return String( v ).replace( /<[^>]*>/g, ' ' ).replace( /\s+/g, ' ' ).trim();
				}

				for ( var i = 0; i < keys.length && ! text; i++ ) {
					text = readable( o[ keys[ i ] ] );
				}

				// A couple of blocks read better with their own wording.
				if ( type === 'button' && text && o.url ) { text += ' → ' + o.url; }
				if ( type === 'divider' ) {
					text = o.style === 'space' ? ( l10n.space || 'Space' ) : ( l10n.line || 'Line' );
				}
				if ( type === 'spacer' && text ) { text = text + 'px'; }

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
				return fw.clone( config.defaults );
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
