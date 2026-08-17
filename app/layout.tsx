import type { Metadata } from "next";
import { Geist, Geist_Mono } from "next/font/google";
import "./globals.css";

const geistSans = Geist({
  variable: "--font-geist-sans",
  subsets: ["latin"],
});

const geistMono = Geist_Mono({
  variable: "--font-geist-mono",
  subsets: ["latin"],
});

// The public commit identifier lets a deployment smoke test prove that the
// checked release, rather than a previously cached deployment, is serving.
const releaseCommit =
  process.env.VERCEL_GIT_COMMIT_SHA ??
  process.env.GITHUB_SHA ??
  process.env.IRONCORE_RELEASE_SHA ??
  "development";

export const metadata: Metadata = {
  title: "IronCore | Gym management, built to scale",
  description:
    "The multi-tenant gym management platform for memberships, payments, staff and growth.",
  other: {
    "codex-preview": "development",
    "ironcore-release": releaseCommit,
  },
  icons: {
    icon: "/favicon.svg",
    shortcut: "/favicon.svg",
  },
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="en">
      <body
        className={`${geistSans.variable} ${geistMono.variable} antialiased`}
      >
        {children}
      </body>
    </html>
  );
}
