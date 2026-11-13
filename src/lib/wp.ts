/**
 * WordPress → Astro data layer.
 *
 * Content lives in WordPress (hjmiranda.com). At build time we read it from
 * the REST API and turn it into plain objects for the pages. Nothing is
 * fetched in the browser.
 *
 * WP_API     – REST base URL (default: https://hjmiranda.com/wp-json/wp/v2)
 * WP_OFFLINE – set to 1 to build from src/data/projects.sample.json instead
 *              (handy when you're offline or WordPress is down)
 */
import sample from "../data/projects.sample.json";

const API = (import.meta.env.WP_API || "https://hjmiranda.com/wp-json/wp/v2").replace(/\/$/, "");
const OFFLINE = import.meta.env.WP_OFFLINE === "1" || process.env.WP_OFFLINE === "1";

export type Kind = "public" | "nda" | "case-study";

export interface Project {
  id: number;
  slug: string;
  title: string;
  summary: string;
  html: string;
  order: number;
  url: string;
  kind: Kind;
  tags: string[];
  platforms: string[];
  modified: string;
  image: null | {
    url: string;
    large: string;
    width: number;
    height: number;
    alt: string;
  };
}

interface WpProject {
  id: number;
  slug: string;
  modified: string;
  menu_order: number;
  title: { rendered: string };
  excerpt: { rendered: string };
  content: { rendered: string };
  meta?: { hjm_url?: string; hjm_tags?: string; hjm_kind?: string };
  hjm_image: Project["image"];
  hjm_platforms: string[];
}

/** Decode the few HTML entities WordPress puts in titles and excerpts. */
export function decode(text: string): string {
  return text
    .replace(/<[^>]+>/g, "")
    .replace(/&#8217;|&rsquo;/g, "’")
    .replace(/&#8216;|&lsquo;/g, "‘")
    .replace(/&#8220;|&ldquo;/g, "“")
    .replace(/&#8221;|&rdquo;/g, "”")
    .replace(/&#8211;|&ndash;/g, "–")
    .replace(/&#8212;|&mdash;/g, "—")
    .replace(/&#038;|&amp;/g, "&")
    .replace(/&nbsp;/g, " ")
    .replace(/\s+/g, " ")
    .trim();
}

function toProject(p: WpProject): Project {
  const kind = (p.meta?.hjm_kind || "public") as Kind;
  return {
    id: p.id,
    slug: p.slug,
    title: decode(p.title.rendered),
    summary: decode(p.excerpt.rendered),
    html: p.content.rendered,
    order: p.menu_order ?? 0,
    url: p.meta?.hjm_url || "",
    kind: ["public", "nda", "case-study"].includes(kind) ? kind : "public",
    tags: (p.meta?.hjm_tags || "").split(",").map((t) => t.trim()).filter(Boolean),
    platforms: p.hjm_platforms || [],
    modified: p.modified,
    image: p.hjm_image && p.hjm_image.url ? p.hjm_image : null
  };
}

let cache: Project[] | null = null;

/** All projects, in the order set in WordPress (Page Attributes › Order). */
export async function getProjects(): Promise<Project[]> {
  if (cache) return cache;

  let raw: WpProject[];

  if (OFFLINE) {
    raw = sample as WpProject[];
  } else {
    const fields = "id,slug,title,excerpt,content,menu_order,meta,hjm_image,hjm_platforms,modified";
    const res = await fetch(`${API}/projects?per_page=100&orderby=menu_order&order=asc&_fields=${fields}`);
    if (!res.ok) {
      throw new Error(`WordPress API returned ${res.status} for ${API}/projects. Set WP_OFFLINE=1 to build from sample data.`);
    }
    raw = await res.json();
  }

  cache = raw.map(toProject).sort((a, b) => a.order - b.order);
  return cache;
}

/** Platform names in a fixed order, with how many projects use each. */
export function platformCounts(projects: Project[]) {
  const order = ["WordPress", "WooCommerce", "Webflow", "Squarespace"];
  const counts = new Map<string, number>();
  projects.forEach((p) => p.platforms.forEach((name) => counts.set(name, (counts.get(name) || 0) + 1)));
  return [...counts.entries()]
    .sort((a, b) => (order.indexOf(a[0]) + 99) % 99 - (order.indexOf(b[0]) + 99) % 99 || a[0].localeCompare(b[0]))
    .map(([name, count]) => ({ name, count, key: name.toLowerCase().replace(/[^a-z0-9]+/g, "-") }));
}

export const slugify = (name: string) => name.toLowerCase().replace(/[^a-z0-9]+/g, "-");
