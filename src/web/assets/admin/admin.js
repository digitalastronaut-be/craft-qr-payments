import "./admin.scss";
import { Chart } from "chart.js/auto";

window.Chart = Chart;

// "Customize sources" is a client-side-only menu item (Craft.BaseElementIndex.getSourceActions()) with no
// server-side toggle, so it's dropped here per element type rather than via allowAdminChanges.
Craft.registerElementIndexClass(
  "digitalastronaut\\craftqrpayments\\elements\\Payment",
  Craft.BaseElementIndex.extend({
    getSourceActions: function () {
      return [];
    },
  })
);
