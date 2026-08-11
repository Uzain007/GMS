import type { MetadataRoute } from "next";

export default function manifest(): MetadataRoute.Manifest {
  return {
    name: "IronCore Gym",
    short_name: "IronCore",
    description: "Private gym membership, access, classes and training.",
    start_url: "/",
    display: "standalone",
    background_color: "#f6f4f1",
    theme_color: "#17151d",
    icons: [
      { src: "/favicon.svg", sizes: "any", type: "image/svg+xml", purpose: "any" },
      { src: "/favicon.svg", sizes: "any", type: "image/svg+xml", purpose: "maskable" },
    ],
  };
}
