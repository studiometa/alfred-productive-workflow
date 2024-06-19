import process from 'node:process';

const [, , route] = process.argv;

/** @type {RequestInit} */
const init = {
  headers: {
    'X-Auth-Token': process.env.PRODUCTIVE_AUTH_TOKEN ?? '',
    'X-Organization-Id': process.env.PRODUCTIVE_ORG_ID ?? '',
    'Content-Type': 'application/vnd.api+json',
  },
};

let response = await fetch(
  `https://api.productive.io/api/v2/${route}`,
  init
).then((response) => response.json());

if (response.errors) {
  console.log(response);
  process.exit();
}

// Get all pages.
// const items:[] = response.data;
// while (response.links.next && response.links.next !== response.links.last) {
//   console.log('fetching', decodeURIComponent(response.links.next));
//   response = await fetch(response.links.next, init).then(response => response.json());
//   items.concat(response.data);
// }

console.log(response.meta.total_count);

export {};
