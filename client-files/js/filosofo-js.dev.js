var FilosofoJS = function(scope) {
	var addEvent = function( obj, type, fn ) {
		if (obj.addEventListener)
			obj.addEventListener(type, fn, false);
		else if (obj.attachEvent)
			obj.attachEvent('on' + type, function() { return fn.call(obj, window.event);});
	},

	d = document,

	XHR = (function() { 
		var i, 
		fs = [
		function() { // for legacy eg. IE 5 
			return new scope.ActiveXObject("Microsoft.XMLHTTP"); 
		}, 
		function() { // for fully patched Win2k SP4 and up 
			return new scope.ActiveXObject("Msxml2.XMLHTTP.3.0"); 
		}, 
		function() { // IE 6 users that have updated their msxml dll files. 
			return new scope.ActiveXObject("Msxml2.XMLHTTP.6.0"); 
		}, 
		function() { // IE7, Safari, Mozilla, Opera, etc (NOTE: IE7 native version does not support overrideMimeType or local file requests)
			return new XMLHttpRequest();
		}]; 

		// Loop through the possible factories to try and find one that
		// can instantiate an XMLHttpRequest object that works.

		for ( i = fs.length; i--; ) { 
			try { 
				if ( fs[i]() ) { 
					return fs[i]; 
				} 
			} catch (e) {} 
		}
	})(),

	/**
	 * Post a xhr request
	 * @param url The url to which to post
	 * @data The associative array of data to post, or a string of already-encoded data
	 * @callback The method to call upon success
	 */
	postReq = function(url, data, callback) {
		url = url || 'admin-ajax.php';
		data = data || {};
		var dataString, request = new XHR;
		dataString = serialize(data);
		try {
			if ( 'undefined' == typeof callback ) {
				callback = function() {};
			}
			request.open('POST', url, true);
			request.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
			request.onreadystatechange = function() {
				if ( 4 == request.readyState ) {
					request.onreadystatechange = function() {};
					if ( 200 <= request.status && 300 > request.status || ( 'undefined' == typeof request.status ) )
						callback(request.responseText);
				}
			}
			request.send(dataString);
		} catch(e) {};
	},
	
	/** 
	 * Whether the property is of this particular object's
	 * @param obj The object whose property we're interested in.
	 * @param property The property which we're interested in.
	 * @return true if The property does not originate higher in the prototype chain.
	 */
	isObjProp = function(obj, property) {
		var p = obj.constructor.prototype[property];
		return ( 'undefined' == typeof p || property !== obj[p] );
	},

	/**
	 * Serialize an associative array
	 * @param array a The associative array to serialize.
	 * @uses urlencode, isObjProp
	 * @return string The serialized string.
	 */
	serialize = function(a) {
		var i, j, s = [];
		for( i in a ) {
			if ( isObjProp(a, i) ) {
				// if the object is an array itself
				if ( '[]' == i.substr(i.length - 2, i.length) ) {
					for ( j = 0; j < a[i].length; j++ ) {
						s[s.length] = urlencode(i) + '=' + urlencode(a[i][j]);
					}
				} else {
					s[s.length] = urlencode(i) + '=' + urlencode(a[i]);
				}
			}
		}
		return s.join('&');
	},

	urlencode = (function() {
		var f = function(s) {
			return encodeURIComponent(s).replace(/%20/,'+').replace(/(.{0,3})(%0A)/g,
				function(m, a, b) {return a+(a=='%0D'?'':'%0D')+b;}).replace(/(%0D)(.{0,3})/g,
				function(m, a, b) {return a+(b=='%0A'?'':'%0A')+b;});
		};

		if (typeof encodeURIComponent != 'undefined' && String.prototype.replace && f('\n \r') == '%0D%0A+%0D%0A') {
			return f;
		}
	})(),

	/**
	 * Get the object that was the target of an event
	 * @param object e The event object (or null for ie)
	 * @return object The target object.
	 */
	getEventTarget = function(e) {
		e = e || window.event;
		return e.target || e.srcElement;
	},


	/**
	 * Animation methods
	 */
	lerp = function(start, end, value) {
		return ( ( 1 - value ) * start ) + ( value * end );
	},

	hermite = function(start, end, value) {
		var i = lerp(start, end, value * value * ( 3 - 2 * value ));
		return i;
	},

	inProgress = false,
	/**
	 * Scroll to the given element.
	 *
	 * @uses hermite
	 * @uses inProgress
	 */
	scrollToElement = function(el) {
		if ( inProgress )
			return;

		var elCopy = el,
		elTop = 0,
		browserTop = 0,
		rate = 25,
		time = 400,
		steps = time / rate,
		distance, inc, i;
		// assign element's position from the top to elTop
		while ( elCopy.offsetParent && elCopy != d.dElement ) {
			elTop += elCopy.offsetTop;
			elCopy = elCopy.offsetParent;
		}

		elTop = elTop - 30;
		elTop = 0 > elTop ? 0 : elTop;

		// assign browser's position from the top to browserTop
		if ( d.documentElement && d.documentElement.scrollTop ) {
			browserTop = d.documentElement.scrollTop;
		} else if ( d.body && d.body.scrollTop ) {
			browserTop = d.body.scrollTop;
		} else if ( d.getElementsByTagName('body') ) {
			browserTop = d.getElementsByTagName('body')[0].scrollTop;
		}

		// distance = Math.abs(browserTop - elTop);
		distance = browserTop - elTop;
		inc = distance / steps;
		for ( i = 0; i < steps; i++ ) {
			(function() {
				var pos = Math.ceil(browserTop - (hermite(0, 1, (i / steps)) * distance)),
				k = i,
				last = ( i + 1 ) < steps ? false : true;

				setTimeout(function() {
					if ( last ) {
						inProgress = false;
					}
					scrollTo(0, pos);
				}, k * rate);
			})();
		}
	},

	Animation = function(diff, callback) { 
		return {
			animate:function() {
				if ( this.inProgress )
					return;
				this.inProgress = true;
					
				callback = callback || function() {};

				var rate = 20,
				time = 500,
				steps = time / rate,
				i,
				last = false,
				state,
				that = this;
				

				for ( i = 0; i < steps; i++ ) {
					last = ( i + 1 ) < steps ? false : true;
					state = 0 < diff ? hermite(0, 1, (i / steps)) * diff : hermite(1, 0, (i / steps)) * diff;
					(function(cb) {
						var k = i,
						l = last,
						curDiff = state;
						setTimeout(function() {
							if ( l )
								that.inProgress = false;
							cb.apply(that, [curDiff, l]);
						}, k * rate);
					})(callback);
				}
			}
		}
	},

	fade = function(obj, dir, callback) {
		if ( ! obj )
			return;
		dir = dir || -1;
		callback = callback || function(){};
		if ( -1 === dir ) {
			obj.style.opacity = 1;
			obj.style.filter = 'alpha(opacity=100)';
		} else if ( 1 === dir ) {
			obj.style.opacity = 0;
			obj.style.filter = 'alpha(opacity=0)';
			obj.style.display = 'block';	
		}


		var fadeCallback = function(curDiff, isLast) {
			var o = 100 + curDiff * dir;
			obj.style.opacity = o / 100;
			obj.style.filter = 'alpha(opacity=' + o + ')';
			if ( isLast ) {
				callback.call(obj);
				if ( -1 === dir )
					obj.style.display = 'none';
				else	
					obj.style.display = 'block';
			}
		},
		animator;

		if ( obj ) {
			if ( -1 === dir ) {
				animator = new Animation(100, fadeCallback),
				animator.animate();
			} else {
				animator = new Animation(-100, fadeCallback);
				animator.animate();
			}
		}
	},

	/**
	 * End animation
	 */

	/**
	 * Start custom event handlers
	 */

	/**
	 * Assign the callback to be triggered when an element of that class
	 * 	or one of its descendents is clicked.
	 * @param string className The name of the class to check for.
	 * @param function callback The callback to call.
	 * 	The first argument passed to callback is the event object.
	 * 	The value of -this- within the callback is the element with the given class.
	 * @param DOMElement parentEl Optional. The element to which to attach the delegated event listener.
	 * 	Default is document
	 */
	attachClassClickListener = function( className, callback, parentEl ) {
		if ( ! parentEl )
			parentEl = d;
		if ( ! className || ! callback )
			return false;

		(function(className, callback, parentEl) {
			var re = new RegExp( '\\b' + className + '\\b' );
			addEvent( parentEl, 'click', function(e) {
				var result = true, 
				target = getEventTarget(e);
				do {
					if ( target.className && re.exec( target.className ) ) {
						result = callback.call( target, e );	
						if ( ! result ) {
							if ( e.stopPropagation )
								e.stopPropagation();
							if ( e.preventDefault )
								e.preventDefault();
							e.cancelBubble = true;
							e.returnValue = false;
							return false;
						} else {
							return true;
						}
					} else {
						target = target.parentNode;
					}
				} while ( target && target != parentEl );
			});
		})(className, callback, parentEl);
	},
	
	/**
	 * End custom event handlers
	 */

	/**
	 * Ready a callback for whichever occurs first: DOMContentLoaded or window.onload
	 *
	 * @param function callback The callback to call at that event.
	 */
	ready = function( callback ) {
		if ( callback )
			loadedCallback = callback;
		addEvent(d, 'DOMContentLoaded', eventDOMLoaded );
		addEvent(window, 'load', eventDOMLoaded );
	},
	
	initialized = false,
	loadedCallback = function() {},
	eventDOMLoaded = function() {
		if ( initialized ) {
			return false;
		}
		initialized = true;

		loadedCallback();
	}	

	return {
		addEvent:addEvent,
		Animation:Animation,
		attachClassClickListener:attachClassClickListener, 
		doWhenReady:ready,
		fade:fade,
		getEventTarget:getEventTarget,
		isObjProperty:isObjProp,
		postReq:postReq,
		scrollToElement:scrollToElement
	}
}
