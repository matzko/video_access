var VideoAccess = (function(f, upBind, json, scope) {
	if ( ! f || ! upBind )
		return false;
	
	var d = document,

	attachUploadFormListeners = function( uploadForm ) {
		if ( ! uploadForm ) 
			return false;

		var changeableFields = ['video-upload-title-field', 'video-upload-desc-field', 'video-upload-tags-field', 'video-upload-privacy-public', 'video-upload-privacy-privacy'],
		el,
		i = changeableFields.length,

		top = 0,
		previousTop = 0,
		left = 0,
		previousLeft = 0,

		mouseDown = false,	
		eventMouseDown = function(e) {
			if ( ! e )
				e = window.event;
			mouseDown = true;

			previousLeft = e.clientX;
			previousTop = e.clientY;
		},

		formWrap = d.getElementById( 'video-lightbox-wrap' ),
		lightboxHeader = d.getElementById( 'wp-flexible-uploader-header' ),
		eventMouseMove = function(e) {
			if ( mouseDown ) {
				if ( ! e )
					e = window.event;

				left += parseInt( e.clientX - previousLeft, 10 );
				top += parseInt( e.clientY - previousTop, 10 );

				formWrap.style.left = left + 'px';
				formWrap.style.top = top + 'px';

				previousLeft = e.clientX;
				previousTop = e.clientY;

				if ( e.preventDefault )
					e.preventDefault();

				if ( e.stopPropagation )
					e.stopPropagation();

				e.returnValue = false;
				e.cancelBubble = true;

				return false;
			}
		}

		eventMouseUp = function(e) {
			mouseDown = false;
		};

		/* make lightbox draggable */
		if ( lightboxHeader ) {
			f.addEvent( lightboxHeader, 'mousedown', eventMouseDown );
			f.addEvent( d, 'mousemove', eventMouseMove );
			f.addEvent( d, 'mouseup', eventMouseUp );
		}

		f.addEvent( uploadForm, 'submit', eventSubmitUploadForm );

		while ( i-- ) {
			el = d.getElementById(changeableFields[i]);
			if ( el ) {
				f.addEvent( el, 'change', registerFormChange );
				f.addEvent( el, 'keydown', registerFormChange );
			}
		}
		
		upBind.attachUploaderCallback( 'FilesAdded', function( up, files ) {
			if ( ! files )
				files = [];
			var i = files.length,
			fileField = d.getElementById( 'file-upload-field' );

			if ( fileField ) {
				fileField.value = '';

				while ( i-- ) {
					fileField.value += files[i].name + ' (' + plupload.formatSize( files[i].size ) + '), ';	
				}

				fileField.value = fileField.value.slice(0, fileField.value.length - 2 );
			}
		} );

		upBind.attachUploaderCallback( 'FileUploaded', function( up, file ) {
			var titleField = d.getElementById( 'video-upload-title-field' ),
			attachIDField = d.getElementById('flexible-uploader-attachment-id'),
			attachID = attachIDField && attachIDField.value ? parseInt( attachIDField.value, 10 ) : 0,
			metaDataWrap = d.getElementById('video-metadata-wrap');	

			// give the video object a title of the file's name, by default
			if ( titleField && file.name && '' == titleField.value ) {
				titleField.value = file.name;
			}

			if ( metaDataWrap && attachID ) {
				startTranscodingCheck( attachID, metaDataWrap );
				disableUploadElements(); 
				saveUploadForm( uploadForm ); 
			}
		});

		upBind.attachUploaderCallback( 'Error', function( up, err ) {
			if ( err.message ) {
				errorMessageHandler( err.message ); 
			}
		});
		
		upBind.attachUploaderCallback( 'Init', function( up ) {
			disableSaveButton();
		});

		upBind.setErrorHandler( errorMessageHandler ); 
	},
	
	errorMessageHandler = function( msg ) {
		var metaDataWrap = d.getElementById('video-metadata-wrap');

		if ( metaDataWrap ) {
			metaDataWrap.innerHTML = '';
			f.fade( metaDataWrap, -1, function() {
				var wrap = d.createElement('p');	
				wrap.className = 'error';
				wrap.appendChild( d.createTextNode( msg ) );
				metaDataWrap.appendChild( wrap );
				f.fade( this, 1 );
			} );
		} else {
			alert( msg );
		}
	},

	formHasChanged = false,
	registerFormChange = function(ev) {
		formHasChanged = true;
		var saveButton = enableSaveButton();
		saveButton.innerHTML = videoAccessL10n._saveButtonCur;
	},

	disableSaveButton = function() {
		var saveButton = d.getElementById( 'video-data-save-button' );
		if ( saveButton ) {
			saveButton.setAttribute('disabled', 'disabled');
		}

		return saveButton;
	},
	
	enableSaveButton = function() {
		var saveButton = d.getElementById( 'video-data-save-button' );
		if ( saveButton ) {
			saveButton.disabled = false;
		}

		return saveButton;
	},

	disableUploadElements = function() {
		var fields = ['wp-flexible-browse-button', 'file-upload-button', 'file-upload-field'],
		i = fields.length,
		el;
		while( i-- ) {
			el = d.getElementById( fields[i] );
			if ( el ) {
				f.fade( el );
			}
		}
	},

	eventClickDeleteLink = function( ev ) {
		if ( videoAccessL10n && videoAccessL10n.confirm_delete_video ) {
			if ( ! confirm( videoAccessL10n.confirm_delete_video ) ) {
				return false;
			}
		}
		return true;
	},

	eventClickVideoThumbLink = function( ev ) {
		var matches = this && this.className ? /video-thumb-link-(\d+)/.exec( this.className ) : [],
		videoID = matches && matches[1] ? parseInt( matches[1], 10 ) : 0;

		loadVideoDataAndPlayIt( videoID ); 
		return false;
	},
	
	eventSubmitUploadForm = function( ev ) {
		saveUploadForm( this ); 
		if ( ev.preventDefault )
			ev.preventDefault();
		ev.returnValue = false;
		return false;
	},

	eventVideoDataSaved = function( result ) {
		var saveButton = disableSaveButton(),
		links = [], i,
		metaDataWrap = d.getElementById('video-metadata-wrap'),
		videoObjIDField = d.getElementById( 'video-access-video-id' );

		if ( videoAccessL10n && videoAccessL10n.saved ) {
			saveButton.innerHTML = videoAccessL10n.saved;
		}

		if ( videoObjIDField && result['video-object-id'] ) {
			videoObjIDField.value = result['video-object-id'];
		}

		// change the links if updated info has changed them
		if ( 
			metaDataWrap && 
			result['old-video-url'] &&
			result['new-video-url'] &&
			result['old-video-url'] != result['new-video-url']
		) {
			links = metaDataWrap.getElementsByTagName('a'),
			i = links.length;
			while ( i-- ) {
				if ( links[i].href && result['old-video-url'] == links[i].href ) {
					links[i].href = result['new-video-url'];
				}
			}
		}
	},
	
	loadCommentsSection = function( videoID ) {
		(function( videoID ) {
			wpJSON.request(
				'videoAccess.getVideoComments',
				{'video-id':videoID},
				function( result ) {
					if ( result && result['video-comments-markup'] && result['video-id'] && videoID == result['video-id'] ) {
						setVideoComments( result['video-comments-markup'] );
					}
				}
			);
		})( videoID );
	},

	loadVideoDataAndPlayIt = function( videoID ) {
		(function( videoID ) {
			wpJSON.request(
				'videoAccess.getVideoURL',
				{'video-id':videoID},
				function( result ) {
					if ( result && result['video-url'] && result['video-id'] && videoID == result['video-id'] ) {
						playVideoFromData( videoID, result['video-url'], result['video-height'], result['video-width'] ); 
						if ( result['video-title'] ) {
							setVideoTitle( result['video-title'] );
						}

						loadCommentsSection( videoID );
					}
				}
			);
		})(videoID);
	},
	
	setVideoComments = function( markup ) {
		var commentsWrap = d.getElementById( 'single-video-comments-wrap' );
		if ( commentsWrap ) {
			commentsWrap.innerHTML = markup;
		}
	},

	setVideoTitle = function( title ) {
		var titleWrap = d.getElementById( 'single-video-title' );
		if ( titleWrap ) {
			titleWrap.innerHTML = title;
		}
	},

	playVideoFromData = function( videoID, url, height, width ) {
		if ( 'undefined' != typeof flowplayer ) {
			var listItem = url,
			wrapper = d.getElementById( 'player-single-video-object-' + videoID );
			if ( height && width ) {
				listItem = {scaling:'orig',url:url,height:height,width:width};
				if ( wrapper ) {
					wrappper.style.height = height + 'px';
					wrappper.style.width = width + 'px';
				}
			}
			flowplayer(0).setPlaylist([listItem]);
			flowplayer(0).play();
		}
	},

	saveUploadForm = function( form ) {
		var data = f.getFormData( form );
		saveButton = disableSaveButton();

		if ( videoAccessL10n ) {
			if ( ! videoAccessL10n._saveButtonCur ) {
				videoAccessL10n._saveButtonCur = saveButton.innerHTML;
			}
			saveButton.innerHTML = videoAccessL10n.processing; 
		}
		
		wpJSON.request(
			'videoAccess.saveVideoPost',
			{'form-data':data},
			eventVideoDataSaved 
		);
	},
	
	startTranscodingCheck = function( attachID, messageEl ) {
		if ( ! json )
			return false;

		var checkOnProgress = function() {
			wpJSON.request(
				'videoAccess.checkTranscodeStatus',
				{'attachment-id':attachID},
				checkResponse 
			);
		},
		
		checkResponse = function( result ) {
			if ( result.status && 1 === parseInt( result.status, 10 ) ) {
			
				clearInterval( intervalID );
				if ( messageEl ) {
					messageEl.innerHTML = '';
					var img = d.createElement('img'),
					link = d.createElement('a');
					if ( result.thumb ) {
						if ( result['video-url'] ) {
							link.href = result['video-url'];
						}
						messageEl.innerHTML = '<span class="status-message">' +
							result.message +
						'</span>';

						img.src = result.thumb;
						(function( el, img, link ) {
							f.fade( el, -1, function() {
								if ( link.href ) {
									link.appendChild( img );
									el.appendChild( link );
								} else {
									el.appendChild( img );
								}
								f.fade( el, 1 ); 
							} );
						})( messageEl, img, link );
					}
				}
			} else if ( ! result.status && result.message ) {
				if ( messageEl ) {
					if ( result.transcode_complete || 0 === result.transcode_complete ) {
						messageEl.innerHTML = '<div class="uploader-progress-bar-wrap">' +
							'<div class="uploader-progress-bar" style="width:' + result.transcode_complete + '%;">' +
								result.transcode_complete + '%' +
							'</div></div> ' +
							'<span class="status-message">' +
								result.message +
							'</span>';

					} else {
						messageEl.innerHTML = '<span class="status-message">' +
							result.message +
						'</span>';
					}
				}
			}
		},

		intervalID = setInterval( checkOnProgress, 5000 );

		checkOnProgress();
	},

	init = function() {
		attachUploadFormListeners( d.getElementById('wp-flexible-uploader-form') ); 
		f.attachClassClickListener( 'delete-video-link', eventClickDeleteLink, d.getElementById( 'main' ) ); 		
		f.attachClassClickListener( 'video-thumb-link', eventClickVideoThumbLink, d.getElementById( 'main' ) ); 		
	}

	f.doWhenReady( init );
})( 'undefined' != typeof FilosofoJS ? new FilosofoJS() : false, 'undefined' != typeof FlexibleUploaderJS ? FlexibleUploaderJS : false, 'undefined' != typeof wpJSON ? wpJSON :false, this ); 
