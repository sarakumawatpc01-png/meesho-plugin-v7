<?php

// Provide a tabbed interface — sanitize input
$allowed_tabs = array( 'import', 'products', 'blogs', 'orders', 'seo', 'analytics', 'copilot', 'logs', 'settings' );
$active_tab   = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'products';
if ( ! in_array( $active_tab, $allowed_tabs, true ) ) {
	$active_tab = 'products';
}

?>
<div class="wrap">
	<h1>Meesho Master v6</h1>
	
	<h2 class="nav-tab-wrapper">
		<a href="?page=meesho-master&tab=import" class="nav-tab <?php echo $active_tab === 'import' ? 'nav-tab-active' : ''; ?>">📥 Import</a>
		<a href="?page=meesho-master&tab=products" class="nav-tab <?php echo $active_tab === 'products' ? 'nav-tab-active' : ''; ?>">📦 Products</a>
		<a href="?page=meesho-master&tab=blogs" class="nav-tab <?php echo $active_tab === 'blogs' ? 'nav-tab-active' : ''; ?>">📝 Blogs</a>
		<a href="?page=meesho-master&tab=orders" class="nav-tab <?php echo $active_tab === 'orders' ? 'nav-tab-active' : ''; ?>">📋 Orders</a>
		<a href="?page=meesho-master&tab=seo" class="nav-tab <?php echo $active_tab === 'seo' ? 'nav-tab-active' : ''; ?>">🔍 SEO / AEO / GEO</a>
		<a href="?page=meesho-master&tab=analytics" class="nav-tab <?php echo $active_tab === 'analytics' ? 'nav-tab-active' : ''; ?>">📈 Analytics</a>
		<a href="?page=meesho-master&tab=copilot" class="nav-tab <?php echo $active_tab === 'copilot' ? 'nav-tab-active' : ''; ?>">🤖 Copilot</a>
		<a href="?page=meesho-master&tab=logs" class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">📝 Logs</a>
		<a href="?page=meesho-master&tab=settings" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">⚙️ Settings</a>
	</h2>

	<div class="meesho-master-content">
		<?php
		switch ( $active_tab ) {
			case 'import':
				include MEESHO_MASTER_PLUGIN_DIR . 'admin/partials/tabs/tab-import.php';
				break;
			case 'products':
				include MEESHO_MASTER_PLUGIN_DIR . 'admin/partials/tabs/tab-products.php';
				break;
			case 'blogs':
				include MEESHO_MASTER_PLUGIN_DIR . 'admin/partials/tabs/tab-blogs.php';
				break;
			case 'orders':
				include MEESHO_MASTER_PLUGIN_DIR . 'admin/partials/tabs/tab-orders.php';
				break;
			case 'seo':
				include MEESHO_MASTER_PLUGIN_DIR . 'admin/partials/tabs/tab-seo.php';
				break;
			case 'analytics':
				include MEESHO_MASTER_PLUGIN_DIR . 'admin/partials/tabs/tab-analytics.php';
				break;
			case 'copilot':
				include MEESHO_MASTER_PLUGIN_DIR . 'admin/partials/tabs/tab-copilot.php';
				break;
			case 'logs':
				include MEESHO_MASTER_PLUGIN_DIR . 'admin/partials/tabs/tab-logs.php';
				break;
			case 'settings':
				include MEESHO_MASTER_PLUGIN_DIR . 'admin/partials/tabs/tab-settings.php';
				break;
		}
		?>
	</div>
</div>
