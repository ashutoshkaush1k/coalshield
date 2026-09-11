// Uploads an image to the CV endpoint and returns the detection result.
import { client } from "./client";

/**
 * POST /vision/analyze as multipart form data.
 *
 * The response carries the score both before and after, so the caller can show what this frame
 * cost the mine without a second request.
 */
export async function analyzeImage(mineId, file) {
  const form = new FormData();
  form.append("mine_id", String(mineId));
  form.append("file", file);

  const { data } = await client.post("/vision/analyze", form, {
    headers: { "Content-Type": "multipart/form-data" },
  });
  return data;
}

// Mirrors backend/app/utils/files.py IMAGE_SUFFIXES. Kept in sync deliberately: rejecting a bad
// file here gives an instant message instead of a round trip to a 422.
export const ACCEPTED_IMAGE_TYPES = ["image/jpeg", "image/png", "image/webp", "image/bmp"];
export const ACCEPT_ATTR = ".jpg,.jpeg,.png,.webp,.bmp";
