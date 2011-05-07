var FlexibleUploaderJS = (function(globalScope) {
	var addEvent = function( obj, type, fn ) {
		if (obj.addEventListener)
			obj.addEventListener(type, fn, false);
		else if (obj.attachEvent)
			obj.attachEvent('on' + type, function() { return fn.call(obj, window.event);});
	},

	d = document,

	/**
	 * Determine whether the given element has an element 
	 * in its ancestory with the given class name
	 *
	 * @param DOMElement el The element in question.
	 * @param string The name of the class to check for.
	 * @param DOMElement (Optional) The root at which no 
	 * farther to check.  Default is documentElement.
	 * @return DOMElement|false The element with that
	 * class or false if none found.
	 */
	ancestryHasClass = function( el, theClass, root ) {
		if ( ! root )
			root = document;
		var re = new RegExp('\\b' + theClass + '\\b');

		do {
			if ( el.className && re.exec( el.className ) )
				return el;

			if ( el.parentNode )
				el = el.parentNode;
		} while ( el && el != root );

		return false;
	},


	adminSetup = function() {
		var body = d.getElementsByTagName('body')[0],
		form = d.getElementById( flexibleUploaderFormId );
		if ( form && body ) {
			form.style.display = 'none';
			body.appendChild( form );

			addEvent( d, 'click', eventClickAdmin );
		}
	},

	eventClickAdmin = function(e) {
		var target = e.target || e.srcElement;

		if ( ancestryHasClass( target, 'launch-flexible-uploader-lightbox' ) ) {
			if ( e.stopPropagation )
				e.stopPropagation();
			if ( e.preventDefault )
				e.preventDefault();
			e.cancelBubble = true;
			e.returnValue = false;
			return false;
		}

	},

	upEventCallbacks = {},
	attachUploaderCallback = function( ev, callback ) {
		if ( 'function' != typeof callback )
			return false;
		if ( upEventCallbacks[ ev ] ) {
			upEventCallbacks[ ev ][upEventCallbacks.length] = callback; 
		} else {
			upEventCallbacks[ ev ] = [ callback ]; 
		}
	},

	callUploaderEventCallbacks = function( ev, scope, args ) {
		var i;

		if ( ! scope ) 
			scope = globalScope;
		if ( ! args )
			args = [];

		if ( upEventCallbacks[ev] ) {
			for ( i = 0; i < upEventCallbacks[ev].length; i++ ) {
				if ( 'function' == typeof upEventCallbacks[ev][i] ) {
					upEventCallbacks[ev][i].apply( scope, args );
				}
			}
		} else {
			return false;
		}
	},

	_errorHandler = function( m ) {
		alert( m );
	},
	/**
	 * Set the handler for the error responses.
	 *
	 * @param function The method for handling errors.
	 */
	setErrorHandler = function( fn ) {
		if ( fn ) {
			_errorHandler = fn;
		}
	},

	uploaderSetup = function() {
		if ( 'undefined' == typeof flexibleUploader )
			return;

		var button = d.createElement('a'),
		complete = false,
		container = d.getElementById( flexibleUploaderContainerId ),
		progressBar,
		progWrap,
		up = flexibleUploader,

		handleErrorResponse = function( err ) {
			if ( err.message ) {
				_errorHandler( err.message );
			} else if ( flexibleUploaderErrorCodes && err.code && flexibleUploaderErrorCodes[err.code] ) {
				_errorHandler( flexibleUploaderErrorCodes[err.code] ); 
			}
		},

		handleSuccessResponse = function( result ) {
			var attachInput = d.getElementById( flexibleUploaderAttachmentInputId );
			if ( result.attach_id && attachInput ) {
				attachInput.value = result.attach_id;
			}
		},

		handleUploadResponse = function( resp ) {
			var jsonObj;

			if ( 'undefined' != typeof JSON && resp && resp['response'] ) {
				try {
					jsonObj = JSON.parse( resp['response'] );
					if ( jsonObj.error ) {
						handleErrorResponse( jsonObj.error );
						return false;
					} else if ( jsonObj.result ) {
						handleSuccessResponse( jsonObj.result );
					}
				} catch( err ) {}
			}

			return true;
		},

		progressUpdater = function(up, file, resp) {
			progressBar = d.getElementById( flexibleUploaderProgressBarId );
			progWrap = d.getElementById( flexibleUploaderProgressBarWrap );

			if ( ! file.size )
				return;
			if ( ! resp )
				resp = {};

			if ( handleUploadResponse( resp ) ) {

				complete = !! ( file.loaded == file.size );

				var perc = 100 * ( file.loaded / file.size );


				if ( progressBar ) {
					progressBar.style.width = perc + '%';	
					progressBar.innerHTML = Math.floor( perc ) + '%';
				}
				
				// All files are uploaded
				if (up.total.uploaded == up.files.length) {
					up.stop();

					if ( progWrap ) {
						if ( ! progWrap.className || -1 === progWrap.className.indexOf('active') ) {
							progWrap.className = 'inactive';
						} else {
							progWrap.className = progWrap.className.replace(/\bactive/g, 'inactive'); 
						}
					}
				}

			} else {
				if ( up.stop )
					up.stop();
			}
		};

		if ( progressBar )
			progressBar.style.width = '0%';

		button.className = 'button';
		button.href = '#';
		button.id = flexibleUploaderBrowseButtonId;
		button.innerHTML = 'Select Image';

		up.bind('BeforeUpload', function( up, file ) {
			callUploaderEventCallbacks( 'BeforeUpload', this, [ up, file ] ); 
		});

		up.bind('ChunkUploaded', function( up, file, resp ) {
			callUploaderEventCallbacks( 'ChunkUploaded', this, [ up, file, resp ] ); 
		});

		up.bind('Destroy', function( up ) {
			callUploaderEventCallbacks( 'Destroy', this, [ up ] ); 
		});

		up.bind('Error', function( up, err ) {
			progressUpdater.call(this, up, err );
			callUploaderEventCallbacks( 'Error', this, [ up, err ] ); 
		});

		up.bind('FilesAdded', function( up, files ) {
			callUploaderEventCallbacks( 'FilesAdded', this, [ up, files ] ); 
		});

		up.bind('FilesRemoved', function( up, files ) {
			callUploaderEventCallbacks( 'FilesRemoved', this, [ up, files ] ); 
		});

		up.bind('FileUploaded', function( up, file, resp ) {
			progressUpdater.call(this, up, file, resp);
			callUploaderEventCallbacks( 'FileUploaded', this, [ up, file, resp ] ); 
		});
		
		up.bind('Init', function( up ) {
			callUploaderEventCallbacks( 'Init', this, [ up ] ); 

			var form = d.getElementById( flexibleUploaderFormId ),
			progressBar = d.getElementById( flexibleUploaderProgressBarId ),
			progWrap = d.getElementById( flexibleUploaderProgressBarWrap );

			if ( progressBar )
				progressBar.style.width = '0%';
				
			addEvent( form, 'submit', function(e) {
				if ( ! complete ) {
					up.start();
				
					if ( e.stopPropagation )
						e.stopPropagation();
					if ( e.preventDefault )
						e.preventDefault();
					e.cancelBubble = true;
					e.returnValue = false;
					return false;
				}
			} );

		});
		
		up.bind('PostInit', function( up ) {
			callUploaderEventCallbacks( 'PostInit', this, [ up ] ); 
		});
		
		up.bind('QueueChanged', function( up ) {
			callUploaderEventCallbacks( 'QueueChanged', this, [ up ] ); 
		});
		
		up.bind('Refresh', function( up ) {
			callUploaderEventCallbacks( 'Refresh', this, [ up ] ); 
		});

		up.bind('StateChanged', function( up ) {
			callUploaderEventCallbacks( 'StateChanged', this, [ up ] ); 
			var progWrap = d.getElementById( flexibleUploaderProgressBarWrap );

			if ( up.state == plupload.STARTED ) {
				if ( progWrap && progWrap.className ) {
					progWrap.className = progWrap.className.replace(/inactive/g, '');
				}
			} else {
				if ( progWrap && progWrap.className ) {
					progWrap.className += ' inactive';
				}
			}
		});
		
		up.bind('UploadComplete', function( up, files ) {
			callUploaderEventCallbacks( 'UploadComplete', this, [ up, files ] ); 
		});
		
		up.bind('UploadFile', function( up, file ) {
			callUploaderEventCallbacks( 'UploadFile', this, [ up, file ] ); 
		});

		up.bind('UploadProgress', function( up, file, resp ) {
			progressUpdater.call(this, up, file, resp);
			callUploaderEventCallbacks( 'UploadProgress', this, [ up, file, resp ] ); 
		});
		
		if ( container  )
			up.init();
	},

	initialized = false,
	init = function() {
		if ( initialized )
			return;
		initialized = true;

		uploaderSetup();		

		if ( 'undefined' != typeof flexibleUploaderIsAdmin && flexibleUploaderIsAdmin ) {
			adminSetup();
		}
	}

	addEvent(d, 'DOMContentLoaded', init);
	addEvent(window, 'load', init);

	return {
		attachUploaderCallback:attachUploaderCallback,
		setErrorHandler:setErrorHandler
	}
})(this);
