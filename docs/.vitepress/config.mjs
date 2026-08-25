import { defineConfig } from "vitepress";

export default defineConfig({
  title: "NativePHP Fetch",
  description:
    "Asynchronous native HTTP requests, uploads, and downloads for NativePHP Mobile.",
  base: "/nativephp-fetch/",
  cleanUrls: true,
  lastUpdated: true,
  head: [["meta", { name: "theme-color", content: "#2563eb" }]],
  themeConfig: {
    search: { provider: "local" },
    nav: [
      { text: "Guide", link: "/getting-started" },
      { text: "API", link: "/api-reference" },
      { text: "GitHub", link: "https://github.com/victorycodedev/nativephp-fetch" },
    ],
    sidebar: [
      {
        text: "Getting started",
        items: [
          { text: "Introduction", link: "/" },
          { text: "Installation", link: "/getting-started" },
          { text: "Requests and bodies", link: "/requests" },
        ],
      },
      {
        text: "Core features",
        items: [
          { text: "Uploads", link: "/uploads" },
          { text: "Downloads", link: "/downloads" },
          { text: "Events and responses", link: "/events" },
          { text: "NativeComponent example", link: "/native-component" },
          { text: "Retries and cancellation", link: "/retries-cancellation" },
        ],
      },
      {
        text: "Clients and reference",
        items: [
          { text: "JavaScript", link: "/javascript" },
          { text: "Testing", link: "/testing" },
          { text: "API reference", link: "/api-reference" },
          { text: "Compatibility", link: "/compatibility" },
        ],
      },
    ],
    socialLinks: [
      { icon: "github", link: "https://github.com/victorycodedev/nativephp-fetch" },
    ],
    editLink: {
      pattern: "https://github.com/victorycodedev/nativephp-fetch/edit/main/docs/:path",
      text: "Edit this page on GitHub",
    },
    footer: {
      message: "Released under the MIT License.",
      copyright: "Copyright © Victory Efekpogua",
    },
  },
});
