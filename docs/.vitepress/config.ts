import { defineConfig } from "vitepress";

export default defineConfig({
	base: "/qr-payments/",
	title: "Craft QR Payments",
	description: "EPC QR code SEPA payment requests for Craft CMS",
	themeConfig: {
		logo: "/icons/logo.svg",
		search: {
			provider: "local",
			options: {},
		},
		nav: [
			{ text: "Home", link: "/" },
			{ text: "Guide", link: "/plugin/installation-and-setup" },
			{ text: "Plugin store", link: "https://digitalastronaut.be/" },
			{ text: "Maintained by Digitalastronaut", link: "https://digitalastronaut.be/" },
		],

		sidebar: [
			{
				text: "Plugin",
				items: [
					{ text: "Installation and setup", link: "/plugin/installation-and-setup" },
					{ text: "Settings", link: "/plugin/settings" },
					{ text: "Creating a payment", link: "/plugin/creating-a-payment" },
					{ text: "Payment statuses", link: "/plugin/payment-statuses" },
					{ text: "Processing payments", link: "/plugin/processing-payments" },
					{ text: "Email notifications", link: "/plugin/email-notifications" },
					{ text: "Translations", link: "/plugin/translations" },
				],
			},
			{
				text: "Integration guide",
				items: [
					{ text: "Introduction", link: "/integration/introduction" },
					{ text: "Requesting a payment", link: "/integration/requesting-a-payment" },
					{ text: "The pay page", link: "/integration/the-pay-page" },
					{ text: "Checking payment status", link: "/integration/checking-payment-status" },
				],
			},
		],

		socialLinks: [{ icon: "github", link: "https://github.com/digitalastronaut-be/craft-qr-payments" }],
	},
	sitemap: {
		hostname: "https://qr-payments.digitalastronaut.dev",
	},
});
