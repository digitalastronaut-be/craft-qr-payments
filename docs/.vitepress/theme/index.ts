// https://vitepress.dev/guide/custom-theme

import type { Theme } from "vitepress";
import DefaultTheme from "vitepress/theme";
import { h } from "vue";
import "./style.css";

export default {
	extends: DefaultTheme,
	Layout: () => h(DefaultTheme.Layout, null, {}),
} satisfies Theme;
