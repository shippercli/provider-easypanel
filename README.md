# Shipper CLI EasyPanel Provider

Deploy and operate applications on an EasyPanel instance through its API.

## Version 1 scope

Supported:

- Create or reuse deterministic Shipper-managed projects and app services
- Container image sources
- Public Git sources and EasyPanel GitHub integration sources
- Inline Dockerfile sources
- Environment variables
- Custom domains with EasyPanel-managed HTTPS
- App deployment
- Ownership-guarded service and empty-project cleanup

Not yet supported by the provider:

- Database service lifecycle
- Deployment logs
- Rollback
- Resource limits, mounts, and persistent-volume lifecycle

EasyPanel may provide those capabilities in its UI, but this package does not claim support until their API behavior and cleanup semantics have dedicated tests.

## Install

```bash
composer require shippercli/provider-easypanel
```

## Configure

Keep the API token in an environment variable.

```yaml
providers:
  easypanel:
    url: "https://panel.example.com"
    auth_token: "${EASYPANEL_AUTH_TOKEN}"
    managed_prefix: "shipper-"
    service_name: "app"
    source:
      type: image
      image: "ghcr.io/acme/backend:latest"

projects:
  backend:
    provider: easypanel
    profiles:
      production:
        branch: main
        domain: "api.example.com"
        env:
          APP_ENV: production
```

The resulting EasyPanel project is named `shipper-backend-production`. If no profile domain is supplied, EasyPanel's generated HTTPS domain remains available.

## Source types

### Container image

```yaml
source:
  type: image
  image: "nginx:alpine"
```

### Public Git repository

```yaml
source:
  type: git
  repo: "https://github.com/acme/backend.git"
  ref: main
  path: "/"
```

When `source` is omitted, GitHub, GitLab, and Bitbucket repository metadata from the Shipper project is converted to a public Git URL.

### EasyPanel GitHub integration

```yaml
source:
  type: github
  owner: acme
  repo: backend
  ref: main
  path: "/"
```

The repository must be accessible to the GitHub integration configured in EasyPanel.

### Inline Dockerfile

```yaml
source:
  type: dockerfile
  dockerfile: |
    FROM nginx:alpine
    COPY . /usr/share/nginx/html
```

## Cleanup safety

`shipper destroy` refuses to delete a service unless all of these conditions hold:

- The project name is derived from the configured `managed_prefix`, project, and profile.
- The exact service name matches the provider configuration.
- The service is an app service.
- The service environment contains both Shipper ownership markers.

After deleting the marked service, the provider deletes its project only when `destroy_project` is enabled and no services remain. Set `destroy_project: false` to retain empty managed projects.

## Development

```bash
composer install
composer test
```
