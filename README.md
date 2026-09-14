# NSFWImageModeration

EXPERIMENTAL.

Automated and local image moderation using the [HF image-classification specification](https://huggingface.co/docs/inference-providers/main/tasks/image-classification). Designed for `Falconsai/nsfw_image_detection`.

Ensure `$wgNSFWImageModerationDebug` is disabled in production as it provdes exact classification values that may help with circumvention or bypassing.

SUPPORT WILL NOT BE PROVIDED.

Current issues:

* All flagged images are rejected with no penalty and instant feedback. No override or review/approval is possible.