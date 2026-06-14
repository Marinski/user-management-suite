/* global umsIE, jQuery */
( function ( $ ) {
	'use strict';

	// -------------------------------------------------------------------------
	// Shared state
	// -------------------------------------------------------------------------

	var exportToken = null;
	var importToken = null;
	var importLog   = [];
	var importTotals = { created: 0, updated: 0, skipped: 0, failed: 0 };

	// -------------------------------------------------------------------------
	// Utility
	// -------------------------------------------------------------------------

	function ajax( action, data, success, error ) {
		data.action = action;
		data.nonce  = umsIE.nonce;
		$.post( umsIE.ajaxUrl, data )
			.done( function ( res ) {
				if ( res && res.success ) {
					success( res.data );
				} else {
					var msg = ( res && res.data ) ? res.data : umsIE.i18n.errorGeneric;
					if ( error ) { error( msg ); } else { alert( msg ); }
				}
			} )
			.fail( function () {
				var msg = umsIE.i18n.errorGeneric;
				if ( error ) { error( msg ); } else { alert( msg ); }
			} );
	}

	function setProgress( $bar, $count, processed, total ) {
		var pct = total > 0 ? Math.round( ( processed / total ) * 100 ) : 0;
		$bar.css( 'width', pct + '%' );
		$count.text( processed + ' / ' + total );
	}

	function esc( str ) {
		return $( '<span>' ).text( str ).html();
	}

	// -------------------------------------------------------------------------
	// Export
	// -------------------------------------------------------------------------

	var Export = {

		$btn:      $( '#ums-ie-export-btn' ),
		$status:   $( '#ums-ie-export-status' ),
		$progress: $( '#ums-ie-export-progress' ),
		$bar:      $( '#ums-ie-export-bar' ),
		$count:    $( '#ums-ie-export-count' ),

		init: function () {
			this.$btn.on( 'click', $.proxy( this.start, this ) );
			$( '#ums-ie-select-all' ).on( 'click', function () {
				$( '.ums-ie-col-cb' ).prop( 'checked', true );
			} );
			$( '#ums-ie-select-none' ).on( 'click', function () {
				$( '.ums-ie-col-cb' ).prop( 'checked', false );
			} );
		},

		start: function () {
			var self       = this;
			var columns    = [];
			$( '.ums-ie-col-cb:checked' ).each( function () {
				columns.push( $( this ).val() );
			} );

			if ( ! columns.length ) {
				alert( 'Please select at least one column.' );
				return;
			}

			var roles = [];
			$( '#ums-ie-roles option:selected' ).each( function () {
				roles.push( $( this ).val() );
			} );

			var data = {
				columns:   columns,
				roles:     roles,
				date_from: $( '#ums-ie-date-from' ).val(),
				date_to:   $( '#ums-ie-date-to' ).val(),
				search:    $( '#ums-ie-search' ).val(),
				user_ids:  $( '#ums-ie-user-ids' ).val(),
				delimiter: $( '#ums-ie-delimiter' ).val(),
			};

			self.$btn.prop( 'disabled', true );
			self.$status.text( umsIE.i18n.exporting ).show();
			self.$progress.show();
			setProgress( self.$bar, self.$count, 0, 1 );

			ajax( 'ums_ie_export_init', data, function ( res ) {
				exportToken = res.token;
				self.runBatch( 0, res.total, res.batch_size );
			}, function ( msg ) {
				self.reset( msg );
			} );
		},

		runBatch: function ( offset, total, batchSize ) {
			var self = this;
			ajax( 'ums_ie_export_batch', { token: exportToken, offset: offset }, function ( res ) {
				var processed = Math.min( offset + batchSize, total );
				setProgress( self.$bar, self.$count, processed, total );

				if ( res.done ) {
					self.download( total );
				} else {
					self.runBatch( offset + batchSize, total, batchSize );
				}
			}, function ( msg ) {
				self.reset( msg );
			} );
		},

		download: function ( total ) {
			var self = this;
			self.$status.text( umsIE.i18n.downloading );
			setProgress( self.$bar, self.$count, total, total );

			var url = umsIE.downloadUrl
				+ '?action=ums_ie_download&token=' + encodeURIComponent( exportToken )
				+ '&_wpnonce=' + umsIE.nonce;

			window.location.href = url;

			setTimeout( function () {
				self.$status.text( umsIE.i18n.exported );
				self.$btn.prop( 'disabled', false );
				exportToken = null;
			}, 1500 );
		},

		reset: function ( msg ) {
			this.$status.text( msg || umsIE.i18n.errorGeneric ).show();
			this.$btn.prop( 'disabled', false );
			exportToken = null;
		},
	};

	// -------------------------------------------------------------------------
	// Import — step navigation
	// -------------------------------------------------------------------------

	var Import = {

		currentStep: 1,
		file:        null,
		headers:     [],
		rowCount:    0,

		init: function () {
			this.bindUpload();
			this.bindMapping();
			this.bindOptions();
			this.bindResults();
		},

		goTo: function ( step ) {
			$( '.ums-ie-step' ).hide();
			$( '#ums-ie-step-' + step ).show();
			this.currentStep = step;
		},

		// -- Step 1: upload --

		bindUpload: function () {
			var self = this;
			var $input    = $( '#ums-ie-file-input' );
			var $browse   = $( '#ums-ie-browse-btn' );
			var $name     = $( '#ums-ie-file-name' );
			var $uploadBtn = $( '#ums-ie-upload-btn' );
			var $status   = $( '#ums-ie-upload-status' );
			var $dropzone = $( '#ums-ie-dropzone' );

			$browse.on( 'click', function () { $input.trigger( 'click' ); } );

			$input.on( 'change', function () {
				self.file = this.files[0] || null;
				$name.text( self.file ? self.file.name : '' );
				$uploadBtn.prop( 'disabled', ! self.file );
			} );

			// Drag & drop.
			$dropzone.on( 'dragover dragenter', function ( e ) {
				e.preventDefault();
				$dropzone.addClass( 'ums-ie-drag-over' );
			} ).on( 'dragleave drop', function ( e ) {
				e.preventDefault();
				$dropzone.removeClass( 'ums-ie-drag-over' );
				if ( 'drop' === e.type && e.originalEvent.dataTransfer.files.length ) {
					self.file = e.originalEvent.dataTransfer.files[0];
					$name.text( self.file.name );
					$uploadBtn.prop( 'disabled', false );
				}
			} );

			$uploadBtn.on( 'click', function () {
				if ( ! self.file ) { return; }
				$uploadBtn.prop( 'disabled', true );
				$status.text( umsIE.i18n.uploading ).show();

				var form = new FormData();
				form.append( 'action', 'ums_ie_import_upload' );
				form.append( 'nonce', umsIE.nonce );
				form.append( 'ums_ie_file', self.file );

				$.ajax( {
					url:         umsIE.ajaxUrl,
					type:        'POST',
					data:        form,
					contentType: false,
					processData: false,
				} ).done( function ( res ) {
					if ( res && res.success ) {
						importToken = res.data.token;
						$status.hide();
						self.fetchFileInfo();
					} else {
						var msg = ( res && res.data ) ? res.data : umsIE.i18n.errorGeneric;
						$status.text( msg ).show();
						$uploadBtn.prop( 'disabled', false );
					}
				} ).fail( function () {
					$status.text( umsIE.i18n.errorGeneric ).show();
					$uploadBtn.prop( 'disabled', false );
				} );
			} );
		},

		fetchFileInfo: function () {
			var self = this;
			ajax( 'ums_ie_import_info', { token: importToken }, function ( res ) {
				self.headers  = res.headers;
				self.rowCount = res.row_count;
				self.buildMappingUI( res.headers, res.auto_mapping );
				self.goTo( 2 );
				$( '#ums-ie-row-count' ).text(
					umsIE.i18n.rowCount.replace( '%d', res.row_count )
				);
			}, function ( msg ) {
				$( '#ums-ie-upload-status' ).text( msg ).show();
			} );
		},

		// -- Step 2: field mapping --

		bindMapping: function () {
			var self = this;
			$( '#ums-ie-back-1' ).on( 'click', function () { self.goTo( 1 ); } );
			$( '#ums-ie-to-step-3' ).on( 'click', function () { self.goTo( 3 ); } );
		},

		buildMappingUI: function ( headers, autoMapping ) {
			var $tbody = $( '#ums-ie-mapping-rows' ).empty();

			var options = '<option value="__skip">— ' + esc( 'Skip this column' ) + ' —</option>';
			$.each( umsIE.columns, function ( key, col ) {
				if ( col.importable ) {
					options += '<option value="' + esc( key ) + '">' + esc( col.label ) + '</option>';
				}
			} );

			$.each( headers, function ( i, header ) {
				var suggested = autoMapping[ header ] || '__skip';
				var select = '<select class="ums-ie-mapping-select" data-header="' + esc( header ) + '">'
					+ options
					+ '</select>';
				var $row = $( '<tr><td><code>' + esc( header ) + '</code></td><td>' + select + '</td></tr>' );
				$row.find( 'select' ).val( suggested );
				$tbody.append( $row );
			} );
		},

		getMapping: function () {
			var mapping = {};
			$( '.ums-ie-mapping-select' ).each( function () {
				var header  = $( this ).data( 'header' );
				var col_key = $( this ).val();
				mapping[ header ] = col_key;
			} );
			return mapping;
		},

		// -- Step 3: options --

		bindOptions: function () {
			var self = this;
			$( '#ums-ie-back-2' ).on( 'click', function () { self.goTo( 2 ); } );
			$( '#ums-ie-start-import' ).on( 'click', $.proxy( this.startImport, this ) );
		},

		startImport: function () {
			importTotals = { created: 0, updated: 0, skipped: 0, failed: 0 };
			importLog    = [];
			this.goTo( 4 );
			setProgress( $( '#ums-ie-import-bar' ), $( '#ums-ie-import-count' ), 0, this.rowCount );
			this.runBatch( 0 );
		},

		runBatch: function ( offset ) {
			var self    = this;
			var mapping = this.getMapping();

			var data = {
				token:        importToken,
				offset:       offset,
				mapping:      mapping,
				merge_with:   $( '#ums-ie-merge-with' ).val(),
				found_action: $( '#ums-ie-found-action' ).val(),
				default_role: $( '#ums-ie-default-role' ).val(),
				send_email:   $( '#ums-ie-send-email' ).is( ':checked' ) ? 1 : 0,
			};

			ajax( 'ums_ie_import_batch', data, function ( res ) {
				importTotals.created += res.created;
				importTotals.updated += res.updated;
				importTotals.skipped += res.skipped;
				importTotals.failed  += res.failed;

				if ( res.log && res.log.length ) {
					importLog = importLog.concat( res.log );
				}

				var processed = offset + res.processed;
				setProgress( $( '#ums-ie-import-bar' ), $( '#ums-ie-import-count' ), processed, self.rowCount );

				if ( res.done ) {
					ajax( 'ums_ie_import_done', { token: importToken }, function () {}, function () {} );
					self.showResults();
				} else {
					self.runBatch( offset + res.processed );
				}
			}, function ( msg ) {
				$( '#ums-ie-import-status' ).text( msg ).show();
			} );
		},

		// -- Step 5: results --

		bindResults: function () {
			var self = this;
			$( '#ums-ie-view-log' ).on( 'click', function () {
				self.renderFullLog();
				$( '#ums-ie-full-log' ).toggle();
			} );
			$( '#ums-ie-new-import' ).on( 'click', function () {
				if ( window.confirm( umsIE.i18n.confirmReset ) ) {
					self.resetImport();
				}
			} );
		},

		showResults: function () {
			$( '#ums-ie-res-created' ).text( importTotals.created );
			$( '#ums-ie-res-updated' ).text( importTotals.updated );
			$( '#ums-ie-res-skipped' ).text( importTotals.skipped );
			$( '#ums-ie-res-failed' ).text( importTotals.failed );
			if ( importLog.length ) {
				$( '#ums-ie-view-log-wrap' ).show();
			}
			this.goTo( 5 );
		},

		renderFullLog: function () {
			var $log = $( '#ums-ie-full-log' );
			if ( $log.find( 'table' ).length ) { return; }
			var html = '<table class="widefat"><thead><tr>'
				+ '<th>Row</th><th>Status</th><th>Message</th>'
				+ '</tr></thead><tbody>';
			$.each( importLog, function ( i, entry ) {
				var cls = entry.status === 'created' ? 'ums-ie-ok'
					: entry.status === 'updated' ? 'ums-ie-ok'
					: entry.status === 'skipped' ? 'ums-ie-warn'
					: 'ums-ie-err';
				html += '<tr class="' + cls + '"><td>' + esc( String( entry.row ) )
					+ '</td><td>' + esc( entry.status )
					+ '</td><td>' + esc( entry.msg ) + '</td></tr>';
			} );
			html += '</tbody></table>';
			$log.html( html );
		},

		resetImport: function () {
			importToken  = null;
			importLog    = [];
			importTotals = { created: 0, updated: 0, skipped: 0, failed: 0 };
			$( '#ums-ie-file-input' ).val( '' );
			$( '#ums-ie-file-name' ).text( '' );
			$( '#ums-ie-upload-btn' ).prop( 'disabled', true );
			$( '#ums-ie-upload-status' ).hide();
			$( '#ums-ie-full-log' ).empty().hide();
			$( '#ums-ie-view-log-wrap' ).hide();
			this.goTo( 1 );
		},
	};

	// -------------------------------------------------------------------------
	// Bootstrap
	// -------------------------------------------------------------------------

	$( function () {
		if ( $( '#ums-ie-export-btn' ).length ) {
			Export.init();
		}
		if ( $( '#ums-ie-step-1' ).length ) {
			Import.init();
		}
	} );

} )( jQuery );
