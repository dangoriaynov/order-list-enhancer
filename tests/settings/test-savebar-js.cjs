// Standalone test for the settings save bar: unsaved-changes state (no WordPress,
// no browser). A tiny jQuery stand-in records the handlers ole-settings.js binds,
// then the test fires them and inspects what the bar was told to display.
// The form's serialized value is a variable here, so an event can be fired with
// the form unchanged - which is what the color pickers do while initialising.

let fails = 0;
function ck( c, m ) { console.log( ( c ? 'ok   - ' : 'FAIL - ' ) + m ); if ( ! c ) fails++; }

let timers = [];
const unloadHandlers = [];
let formState = 'ship_enabled=on&rule_keyword%5B%5D=Speedy';

// Run (and drop) every pending timer scheduled for `ms`.
function flush( ms ) {
	const due = timers.filter( t => ms === t.ms );
	timers = timers.filter( t => ms !== t.ms );
	due.forEach( t => t.fn() );
}

// --- fake DOM/jQuery -------------------------------------------------------
function Node( selector ) {
	this.selector = selector;
	this.classes  = new Set();
	this.textVal  = '';
	this.handlers = []; // { events, selector, fn }
}

function Col( nodes ) {
	this.nodes  = nodes;
	this.length = nodes.length;
}
Col.prototype.find = function ( sel ) { return $( sel ); };
Col.prototype.on = function ( events, sel, fn ) {
	if ( 'function' === typeof sel ) { fn = sel; sel = null; }
	this.nodes.forEach( n => n.handlers.push( { events: events, selector: sel, fn: fn } ) );
	return this;
};
Col.prototype.text = function ( v ) {
	if ( undefined === v ) { return this.nodes.length ? this.nodes[ 0 ].textVal : ''; }
	this.nodes.forEach( n => { n.textVal = v; } );
	return this;
};
Col.prototype.serialize = function () { return formState; };
Col.prototype.trigger = function ( ev ) {
	this.nodes.forEach( n => n.handlers
		.filter( h => h.events === ev && ! h.selector )
		.forEach( h => h.fn( { preventDefault: function () {} } ) ) );
	return this;
};
Col.prototype.serializeArray = function () { return [ { name: 'ship_enabled', value: 'on' } ]; };
Col.prototype.css = function () { return this; };
Col.prototype.prop = function () { return this; };
Col.prototype.val = function () { return this; };
Col.prototype.data = function () { return undefined; };
Col.prototype.each = function ( fn ) { this.nodes.forEach( ( n, i ) => fn.call( n, i, n ) ); return this; };
Col.prototype.not = function () { return this; };
Col.prototype.first = function () { return this; };
Col.prototype.clone = function () { return this; };
Col.prototype.append = function () { return this; };
Col.prototype.empty = function () { return this; };
Col.prototype.closest = function () { return this; };
Col.prototype.attr = function () { return this; };
Col.prototype.removeClass = function () { return this; };
Col.prototype.addClass = function ( c ) { this.nodes.forEach( n => n.classes.add( c ) ); return this; };
Col.prototype.filter = function () { return this; };
Col.prototype.get = function () { return { id: 'orders' }; };
Col.prototype.toggleClass = function ( c, on ) {
	this.nodes.forEach( n => { if ( on ) { n.classes.add( c ); } else { n.classes.delete( c ); } } );
	return this;
};

const registry = {};
function node( sel ) {
	if ( ! registry[ sel ] ) { registry[ sel ] = new Node( sel ); }
	return registry[ sel ];
}

const ready = [];
function $( arg ) {
	if ( 'function' === typeof arg ) { ready.push( arg ); return new Col( [] ); }
	if ( 'string' !== typeof arg ) { return new Col( [ node( 'document' ) ] ); }
	return new Col( [ node( arg ) ] );
}
$.fn = {};                       // no wpColorPicker / selectWoo -> init paths bail out
$.post = function () { return { done: function () { return this; }, fail: function () { return this; } }; };
$.each = function () {};

global.jQuery = $;
global.window = {
	location: { hash: '' },
	history: { replaceState: function () {} },
	addEventListener: function ( ev, fn ) { if ( 'beforeunload' === ev ) { unloadHandlers.push( fn ); } },
};
global.document = { documentElement: { lang: 'bg' } };
global.setTimeout = function ( fn, ms ) { timers.push( { fn: fn, ms: ms } ); return timers.length; };
global.ORDELIST_SETTINGS = {
	ajaxUrl: '/ajax',
	nonce: 'n',
	i18n: { saving: 'SAVING', saved: 'SAVED', error: 'ERROR', expired: 'EXPIRED', unsaved: 'UNSAVED' },
};

require( '../../assets/js/ole-settings.js' );
ready.forEach( fn => fn( $ ) );
flush( 0 ); // deferred binding: snapshots the form and attaches the dirty listeners

// --- helpers ---------------------------------------------------------------
const form   = node( '#ole-settings-form' );
const status = node( '.ole-save-status' );
const bar    = node( '.ole-savebar' );

const changeH = form.handlers.find( h => h.events && h.events.indexOf( 'change' ) !== -1 && ':input' === h.selector );
// jQuery runs every delegated handler whose selector matches, and ".ole-rule-add"
// carries two of them (add a row, and re-check the form) - fire both, as a real
// click would, instead of picking one.
const addClicks = form.handlers.filter( h => 'click' === h.events && h.selector && h.selector.indexOf( '.ole-rule-add' ) !== -1 );
const submitH   = form.handlers.find( h => 'submit' === h.events );

function clickAdd() { addClicks.forEach( h => h.fn.call( {} ) ); flush( 0 ); }
function leavingPage() {
	const ev = { preventDefault: function () { this.prevented = true; }, returnValue: undefined };
	unloadHandlers[ 0 ]( ev );
	return '' === ev.returnValue && true === ev.prevented;
}
function submit() { submitH.fn( { preventDefault: function () {} } ); }

// Clicking the save button must drive the save itself, not rely on the form
// dispatching a submit event - and must suppress the implicit submission so the
// request goes out exactly once.
const saveClickH = form.handlers.find( h => 'click' === h.events && 'button[type=submit]' === h.selector );
function clickSave() {
	let prevented = false;
	saveClickH.fn( { preventDefault: function () { prevented = true; } } );
	return prevented;
}

// --- assertions ------------------------------------------------------------
ck( !! changeH, 'a change/input listener is bound for form fields' );
ck( addClicks.length >= 2, 'the add/remove row buttons re-check the form as well as rebuild it' );
ck( 1 === unloadHandlers.length, 'a beforeunload handler is registered' );

// Clean on load.
ck( '' === status.textVal, 'status is empty before any edit' );
ck( ! bar.classes.has( 'is-dirty' ), 'save bar is not marked dirty before any edit' );
ck( ! leavingPage(), 'beforeunload does not block while everything is saved' );

// A change event that leaves the form identical is not an edit: this is what
// wpColorPicker/selectWoo do on init, and a page that loads amber-dirty and
// prompts on every exit would make the warning worthless.
changeH.fn();
ck( ! bar.classes.has( 'is-dirty' ), 'a change event that does not alter the form leaves the bar clean' );
ck( ! leavingPage(), 'beforeunload does not block after a no-op change event' );

// A real edit.
formState = 'rule_keyword%5B%5D=Speedy';
changeH.fn();
ck( 'UNSAVED' === status.textVal, 'editing a field shows the unsaved-changes notice' );
ck( bar.classes.has( 'is-dirty' ), 'editing a field marks the save bar dirty' );
ck( leavingPage(), 'beforeunload blocks while changes are unsaved' );

// Undoing the edit by hand clears the mark - nothing differs from what is stored.
formState = 'ship_enabled=on&rule_keyword%5B%5D=Speedy';
changeH.fn();
ck( ! bar.classes.has( 'is-dirty' ), 'reverting the edit clears the dirty mark' );
ck( '' === status.textVal, 'reverting the edit clears the notice' );

// Adding a rule row: the handler that appends it runs first, so the check is
// deferred a tick - without that it would serialize the old table and miss it.
formState = 'ship_enabled=on&rule_keyword%5B%5D=Speedy&rule_keyword%5B%5D=';
clickAdd();
ck( bar.classes.has( 'is-dirty' ), 'adding a rule row marks the save bar dirty' );

// A successful save clears it; the 4s status reset must not bring the notice back.
let posted = null;
$.post = function () {
	return {
		done: function ( fn ) { posted = fn; return this; },
		fail: function () { return this; },
	};
};
submit();
posted( { success: true } );
ck( ! bar.classes.has( 'is-dirty' ), 'a successful save clears the dirty mark' );
ck( 'SAVED' === status.textVal, 'a successful save reports "saved"' );
flush( 4000 );
ck( '' === status.textVal, 'the status clears after the save, with no stale unsaved notice' );
ck( ! leavingPage(), 'beforeunload stops blocking once the save succeeded' );

// Editing while the request is in flight: the response stored the older form,
// so the newer edit is still unsaved and must stay flagged.
submit();
formState = 'ship_enabled=on&rule_keyword%5B%5D=Econt';
changeH.fn();
posted( { success: true } );
ck( bar.classes.has( 'is-dirty' ), 'an edit made during the save stays flagged after it succeeds' );
flush( 4000 );
ck( 'UNSAVED' === status.textVal, 'the in-flight edit is reported as unsaved once the status resets' );
ck( leavingPage(), 'beforeunload still blocks for the in-flight edit' );

// The button click alone must produce the save request.
let postCalls = 0;
$.post = function () {
	postCalls++;
	return {
		done: function ( fn ) { posted = fn; return this; },
		fail: function () { return this; },
	};
};
formState = 'ship_enabled=off';
changeH.fn();
status.textVal = '';
const prevented = clickSave();
ck( prevented, 'the save click suppresses the implicit form submission' );
ck( 1 === postCalls, 'the save click sends exactly one request' );
ck( 'SAVING' === status.textVal, 'the save click reports that saving started' );
posted( { success: true } );
ck( ! bar.classes.has( 'is-dirty' ), 'the click-driven save clears the dirty mark' );
flush( 4000 );

// A failed save must keep the guard armed - the changes are still only local.
formState = 'ship_enabled=off&rule_keyword%5B%5D=Econt';
changeH.fn();
$.post = function () {
	return {
		done: function ( fn ) { fn( { success: false } ); return this; },
		fail: function () { return this; },
	};
};
submit();
ck( bar.classes.has( 'is-dirty' ), 'a failed save keeps the dirty mark' );
ck( 'ERROR' === status.textVal, 'a failed save reports the failure' );
flush( 4000 );
ck( 'UNSAVED' === status.textVal, 'after a failed save the unsaved notice comes back' );

console.log( fails ? '\n' + fails + ' FAILED' : '\nALL PASS' );
process.exit( fails ? 1 : 0 );
