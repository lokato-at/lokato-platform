import { describe, expect, it } from "vitest";
import { mount } from "@vue/test-utils";
import { createRouter, createWebHashHistory } from "vue-router";
import App from "../App.vue";

const router = createRouter({
  history: createWebHashHistory(),
  routes: [
    { path: "/dashboard", component: { template: '<div>Dashboard</div>' } },
    { path: "/admin/home", component: { template: '<div>Admin</div>' } },
    { path: "/:pathMatch(.*)*", redirect: "/dashboard" },
  ],
});

describe("App", () => {
  it("renders the app shell", async () => {
    router.push("/dashboard");
    await router.isReady();

    const wrapper = mount(App, {
      global: {
        plugins: [router],
      },
    });

    expect(wrapper.text()).toContain("Lokato Plattform");
    expect(wrapper.text()).toContain("Dashboard");
    expect(wrapper.text()).toContain("Admin");
  });
});
