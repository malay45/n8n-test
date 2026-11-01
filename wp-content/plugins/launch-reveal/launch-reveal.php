<?php
/**
 * Plugin Name:       Launch Reveal
 * Description:       Shows a full-screen launch image when the site is opened with a specific query parameter (e.g. ?launch=1). One click reveals the website with an animation. Zero assets load on normal visits.
 * Version:           1.0.0
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author:            Akshar Soft Solutions
 * License:           GPL-2.0-or-later
 * Text Domain:       launch-reveal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LAUNCH_REVEAL_VERSION', '1.0.0' );
define( 'LAUNCH_REVEAL_URL', plugin_dir_url( __FILE__ ) );
define( 'LAUNCH_REVEAL_OPTION', 'launch_reveal_settings' );

/**
 * Settings with defaults.
 */
function launch_reveal_settings() {
	$defaults = array(
		'enabled'     => 1,
		'param_key'   => 'launch',
		'param_value' => '1',
		'image'       => '',
		'fit'         => 'contain',
		'bg'          => '#070b24',
		'animation'   => 'curtain',
		'hint'        => 'Click anywhere to launch',
		'confetti'    => 1,
		'clean_url'   => 1,
	);
	$saved = get_option( LAUNCH_REVEAL_OPTION, array() );
	return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
}

/**
 * Does the current request carry the launch query param?
 */
function launch_reveal_is_active() {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return false;
	}
	$s = launch_reveal_settings();
	if ( empty( $s['enabled'] ) || '' === $s['param_key'] ) {
		return false;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display toggle.
	if ( ! isset( $_GET[ $s['param_key'] ] ) ) {
		return false;
	}
	if ( '' === $s['param_value'] ) {
		return true; // Any value matches.
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	return sanitize_text_field( wp_unslash( $_GET[ $s['param_key'] ] ) ) === $s['param_value'];
}

/* ------------------------------------------------------------------------
 * Front end — everything is inline and only printed when the param matches.
 * --------------------------------------------------------------------- */

add_action( 'template_redirect', 'launch_reveal_boot' );
function launch_reveal_boot() {
	if ( ! launch_reveal_is_active() ) {
		return;
	}
	// Tell page caches not to store this variant.
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	nocache_headers();

	add_action( 'wp_head', 'launch_reveal_head', 1 );
	add_action( 'wp_body_open', 'launch_reveal_markup', 1 );
	add_action( 'wp_footer', 'launch_reveal_markup', 1 ); // Fallback for themes without wp_body_open.
	add_action( 'wp_footer', 'launch_reveal_script', 99 );
}

function launch_reveal_image_url( $s ) {
	if ( ! empty( $s['image'] ) ) {
		return $s['image'];
	}
	return LAUNCH_REVEAL_URL . 'assets/launch.webp';
}

function launch_reveal_head() {
	$s   = launch_reveal_settings();
	$img = launch_reveal_image_url( $s );
	$fit = 'cover' === $s['fit'] ? 'cover' : 'contain';
	?>
<link rel="preload" as="image" href="<?php echo esc_url( $img ); ?>" fetchpriority="high">
<style id="launch-reveal-css">
html.lr-lock,html.lr-lock body{overflow:hidden!important}
#lr-overlay{position:fixed;inset:0;z-index:2147483646;background:<?php echo esc_attr( $s['bg'] ); ?>;cursor:pointer;overflow:hidden;-webkit-tap-highlight-color:transparent;outline:none}
#lr-overlay .lr-half{position:absolute;inset:0;background:<?php echo esc_attr( $s['bg'] ); ?> url("<?php echo esc_url( $img ); ?>") center/<?php echo esc_attr( $fit ); ?> no-repeat;will-change:transform}
#lr-overlay[data-fit=contain] .lr-half{background-size:min(92vw,calc(88vh * var(--lr-ratio,1.48))) auto}
#lr-overlay .lr-l{clip-path:inset(0 50% 0 0)}
#lr-overlay .lr-r{clip-path:inset(0 0 0 50%)}
#lr-overlay .lr-glow{position:absolute;top:0;bottom:0;left:50%;width:2px;margin-left:-1px;background:linear-gradient(transparent,#ffb347,#fff,#ffb347,transparent);box-shadow:0 0 24px 6px rgba(255,170,60,.7);opacity:0;pointer-events:none}
#lr-overlay .lr-hint{position:absolute;left:50%;bottom:clamp(16px,4vh,40px);transform:translateX(-50%);display:flex;align-items:center;gap:10px;padding:12px 22px;border-radius:999px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.25);color:#fff;font:600 15px/1.2 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;letter-spacing:.02em;backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);white-space:nowrap;animation:lr-pulse 2s ease-in-out infinite}
#lr-overlay .lr-hint i{width:10px;height:10px;border-radius:50%;background:#f59e0b;box-shadow:0 0 0 0 rgba(245,158,11,.7);animation:lr-ping 1.6s ease-out infinite}
#lr-overlay.lr-go .lr-hint{animation:none;opacity:0;transition:opacity .2s}
#lr-confetti{position:fixed;inset:0;z-index:2147483647;pointer-events:none}
@keyframes lr-pulse{50%{transform:translateX(-50%) scale(1.05)}}
@keyframes lr-ping{to{box-shadow:0 0 0 14px rgba(245,158,11,0)}}
</style>
<script>document.documentElement.classList.add('lr-lock');</script>
	<?php
}

function launch_reveal_markup() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	$s    = launch_reveal_settings();
	?>
<div id="lr-overlay" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr( $s['hint'] ); ?>" tabindex="0" data-fit="<?php echo 'cover' === $s['fit'] ? 'cover' : 'contain'; ?>">
	<div class="lr-half lr-l"></div><div class="lr-half lr-r"></div><div class="lr-glow"></div>
	<?php if ( '' !== $s['hint'] ) : ?>
	<div class="lr-hint"><i></i><?php echo esc_html( $s['hint'] ); ?></div>
	<?php endif; ?>
</div>
	<?php
}

function launch_reveal_script() {
	$s   = launch_reveal_settings();
	$cfg = array(
		'img'       => launch_reveal_image_url( $s ),
		'animation' => $s['animation'],
		'confetti'  => (bool) $s['confetti'],
		'cleanUrl'  => (bool) $s['clean_url'],
		'param'     => $s['param_key'],
	);
	?>
<script id="launch-reveal-js">
(function(){
var C=<?php echo wp_json_encode( $cfg ); ?>,o=document.getElementById('lr-overlay'),h=document.documentElement;
if(!o){h.classList.remove('lr-lock');return;}
if(o.parentNode!==document.body)document.body.appendChild(o);
var im=new Image();im.onload=function(){o.style.setProperty('--lr-ratio',im.naturalWidth/im.naturalHeight)};im.src=C.img;
try{o.focus({preventScroll:true})}catch(e){}
var L=o.querySelector('.lr-l'),R=o.querySelector('.lr-r'),G=o.querySelector('.lr-glow'),busy=0,
reduce=window.matchMedia&&matchMedia('(prefers-reduced-motion: reduce)').matches;
function A(el,k,opt){return el.animate?el.animate(k,opt).finished:Promise.resolve()}
function done(){
 o.remove();h.classList.remove('lr-lock');
 if(C.cleanUrl&&history.replaceState){try{var u=new URL(location.href);u.searchParams.delete(C.param);history.replaceState(history.state,'',u.toString())}catch(e){}}
 document.dispatchEvent(new CustomEvent('launchreveal:done'));
}
function go(e){
 if(busy)return;busy=1;o.classList.add('lr-go');
 var ease='cubic-bezier(.77,0,.18,1)',p;
 if(reduce||!o.animate){p=A(o,[{opacity:1},{opacity:0}],{duration:400,fill:'forwards'});}
 else if(C.animation==='zoom'){
  p=A(o,[{opacity:1,transform:'scale(1)',filter:'blur(0)'},{opacity:0,transform:'scale(1.35)',filter:'blur(14px)'}],{duration:1000,easing:'cubic-bezier(.6,0,.2,1)',fill:'forwards'});
 }else if(C.animation==='iris'){
  var x=e&&e.clientX?e.clientX:innerWidth/2,y=e&&e.clientY?e.clientY:innerHeight/2,c=' at '+x+'px '+y+'px)';
  o.style.background='transparent';
  p=A(o,[{clipPath:'circle(150%'+c},{clipPath:'circle(0%'+c}],{duration:1100,easing:ease,fill:'forwards'});
 }else{ /* curtain: poster splits down the middle and slides apart */
  o.style.background='transparent';
  A(G,[{opacity:0,transform:'scaleY(0)'},{opacity:1,transform:'scaleY(1)',offset:.35},{opacity:0,transform:'scaleY(1)'}],{duration:700,easing:'ease-out',fill:'forwards'});
  var k={duration:1200,delay:250,easing:ease,fill:'forwards'};
  A(L,[{transform:'none'},{transform:'translateX(-55%) rotate(-4deg)',opacity:.6}],k);
  p=A(R,[{transform:'none'},{transform:'translateX(55%) rotate(4deg)',opacity:.6}],k);
 }
 if(C.confetti&&!reduce)setTimeout(confetti,C.animation==='curtain'?350:150);
 p.then(done,done);
}
function confetti(){
 var cv=document.createElement('canvas'),x=cv.getContext('2d'),d=Math.min(devicePixelRatio||1,2),W=innerWidth,H=innerHeight,P=[],cols=['#f59e0b','#fbbf24','#1e3a8a','#3b82f6','#ffffff','#ea580c'],t0=performance.now();
 if(!x)return;cv.id='lr-confetti';cv.width=W*d;cv.height=H*d;x.scale(d,d);document.body.appendChild(cv);
 for(var i=0;i<160;i++){var side=i%2,a=Math.PI/10+Math.random()*Math.PI/3,v=10+Math.random()*16;
  P.push({x:side?W:0,y:H*.9,vx:(side?-1:1)*Math.sin(a)*v,vy:-Math.cos(a)*v*1.15,w:6+Math.random()*6,h:8+Math.random()*8,r:Math.random()*6,vr:(Math.random()-.5)*.3,c:cols[i%cols.length]});}
 (function f(t){var k=t-t0;x.clearRect(0,0,W,H);
  P.forEach(function(p){p.vy+=.28;p.vx*=.985;p.x+=p.vx;p.y+=p.vy;p.r+=p.vr;x.save();x.translate(p.x,p.y);x.rotate(p.r);x.globalAlpha=Math.max(0,1-k/3200);x.fillStyle=p.c;x.fillRect(-p.w/2,-p.h/2,p.w,p.h*Math.abs(Math.cos(p.r*2)));x.restore();});
  if(k<3200)requestAnimationFrame(f);else cv.remove();})(t0);
}
o.addEventListener('click',go);
o.addEventListener('keydown',function(e){if(e.key==='Enter'||e.key===' '||e.key==='Escape'){e.preventDefault();go();}});
})();
</script>
	<?php
}

/* ------------------------------------------------------------------------
 * Admin settings — Settings → Launch Reveal
 * --------------------------------------------------------------------- */

add_action( 'admin_menu', 'launch_reveal_menu' );
function launch_reveal_menu() {
	add_options_page( 'Launch Reveal', 'Launch Reveal', 'manage_options', 'launch-reveal', 'launch_reveal_page' );
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'launch_reveal_links' );
function launch_reveal_links( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=launch-reveal' ) ) . '">Settings</a>' );
	return $links;
}

add_action( 'admin_init', 'launch_reveal_register' );
function launch_reveal_register() {
	register_setting(
		'launch_reveal',
		LAUNCH_REVEAL_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'launch_reveal_sanitize',
		)
	);
}

function launch_reveal_sanitize( $in ) {
	$in  = is_array( $in ) ? $in : array();
	$bg  = isset( $in['bg'] ) ? sanitize_hex_color( $in['bg'] ) : '';
	$key = isset( $in['param_key'] ) ? preg_replace( '/[^A-Za-z0-9_\-]/', '', $in['param_key'] ) : '';
	return array(
		'enabled'     => empty( $in['enabled'] ) ? 0 : 1,
		'param_key'   => '' !== $key ? $key : 'launch',
		'param_value' => isset( $in['param_value'] ) ? sanitize_text_field( $in['param_value'] ) : '',
		'image'       => isset( $in['image'] ) ? esc_url_raw( $in['image'] ) : '',
		'fit'         => ( isset( $in['fit'] ) && 'cover' === $in['fit'] ) ? 'cover' : 'contain',
		'bg'          => $bg ? $bg : '#070b24',
		'animation'   => ( isset( $in['animation'] ) && in_array( $in['animation'], array( 'curtain', 'zoom', 'iris' ), true ) ) ? $in['animation'] : 'curtain',
		'hint'        => isset( $in['hint'] ) ? sanitize_text_field( $in['hint'] ) : '',
		'confetti'    => empty( $in['confetti'] ) ? 0 : 1,
		'clean_url'   => empty( $in['clean_url'] ) ? 0 : 1,
	);
}

add_action( 'admin_enqueue_scripts', 'launch_reveal_admin_assets' );
function launch_reveal_admin_assets( $hook ) {
	if ( 'settings_page_launch-reveal' === $hook ) {
		wp_enqueue_media();
	}
}

function launch_reveal_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$s       = launch_reveal_settings();
	$n       = LAUNCH_REVEAL_OPTION;
	$preview = add_query_arg( $s['param_key'], '' !== $s['param_value'] ? $s['param_value'] : '1', home_url( '/' ) );
	?>
<div class="wrap">
	<h1>Launch Reveal</h1>
	<p>Share this link to show the launch screen: <a href="<?php echo esc_url( $preview ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( $preview ); ?></code></a></p>
	<form method="post" action="options.php">
		<?php settings_fields( 'launch_reveal' ); ?>
		<table class="form-table" role="presentation">
			<tr><th scope="row">Enabled</th><td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[enabled]" value="1" <?php checked( $s['enabled'] ); ?>> Show the launch screen when the query parameter matches</label></td></tr>
			<tr><th scope="row"><label for="lr-key">Query parameter</label></th><td>
				<input id="lr-key" type="text" class="regular-text" name="<?php echo esc_attr( $n ); ?>[param_key]" value="<?php echo esc_attr( $s['param_key'] ); ?>"> =
				<input type="text" name="<?php echo esc_attr( $n ); ?>[param_value]" value="<?php echo esc_attr( $s['param_value'] ); ?>" placeholder="(any value)">
				<p class="description">e.g. <code>launch</code> = <code>1</code> → <code>?launch=1</code>. Leave the value empty to match any value.</p>
			</td></tr>
			<tr><th scope="row"><label for="lr-img">Launch image</label></th><td>
				<input id="lr-img" type="url" class="large-text" name="<?php echo esc_attr( $n ); ?>[image]" value="<?php echo esc_attr( $s['image'] ); ?>" placeholder="Default: bundled Pramukh Academy Website 2.0 poster">
				<p><button type="button" class="button" id="lr-pick">Choose from Media Library</button></p>
				<img id="lr-prev" src="<?php echo esc_url( launch_reveal_image_url( $s ) ); ?>" alt="" style="max-width:420px;height:auto;background:<?php echo esc_attr( $s['bg'] ); ?>;padding:8px;border-radius:6px">
			</td></tr>
			<tr><th scope="row">Image fit</th><td>
				<select name="<?php echo esc_attr( $n ); ?>[fit]">
					<option value="contain" <?php selected( $s['fit'], 'contain' ); ?>>Contain — whole image visible (recommended for posters)</option>
					<option value="cover" <?php selected( $s['fit'], 'cover' ); ?>>Cover — fill the screen (may crop)</option>
				</select>
			</td></tr>
			<tr><th scope="row"><label for="lr-bg">Background colour</label></th><td><input id="lr-bg" type="text" name="<?php echo esc_attr( $n ); ?>[bg]" value="<?php echo esc_attr( $s['bg'] ); ?>" class="small-text" style="width:8em"></td></tr>
			<tr><th scope="row">Reveal animation</th><td>
				<select name="<?php echo esc_attr( $n ); ?>[animation]">
					<option value="curtain" <?php selected( $s['animation'], 'curtain' ); ?>>Curtain split — image splits in the middle and slides apart</option>
					<option value="iris" <?php selected( $s['animation'], 'iris' ); ?>>Iris — circle closes towards the click point</option>
					<option value="zoom" <?php selected( $s['animation'], 'zoom' ); ?>>Zoom &amp; blur — flies towards the viewer and fades</option>
				</select>
			</td></tr>
			<tr><th scope="row"><label for="lr-hint">Hint text</label></th><td><input id="lr-hint" type="text" class="regular-text" name="<?php echo esc_attr( $n ); ?>[hint]" value="<?php echo esc_attr( $s['hint'] ); ?>"><p class="description">Leave empty to hide the hint button.</p></td></tr>
			<tr><th scope="row">Extras</th><td>
				<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[confetti]" value="1" <?php checked( $s['confetti'] ); ?>> Confetti burst on launch</label><br>
				<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[clean_url]" value="1" <?php checked( $s['clean_url'] ); ?>> Remove the query parameter from the address bar after launch</label>
			</td></tr>
		</table>
		<?php submit_button(); ?>
	</form>
</div>
<script>
jQuery(function($){var f;$('#lr-pick').on('click',function(e){e.preventDefault();if(f){f.open();return;}
f=wp.media({title:'Launch image',library:{type:'image'},multiple:false});
f.on('select',function(){var a=f.state().get('selection').first().toJSON();$('#lr-img').val(a.url);$('#lr-prev').attr('src',a.url);});f.open();});});
</script>
	<?php
}
