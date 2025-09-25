# License Server (MyShop)

To provide licensing and update functionality for your WooCommerce plugins, this package implements a simple license server. It exposes REST endpoints for license activation and validation, integrates with WooCommerce and Woo Subscriptions to generate and manage license keys, and delivers plugin updates via signed URLs.

This repository contains a WordPress plugin. To install, copy the `license-server-full` directory into your WordPress `wp-content/plugins` folder and activate it through the WordPress admin panel. Please note that this is a reference implementation and includes stub files for parts of the system that may require further development (e.g. admin UIs, additional middleware, rate limiting). Feel free to extend the provided classes to meet your production requirements.
