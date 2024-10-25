# Configure Purge Subscriptions Using YAML

Purge subscriptions can also be configured using YAML. This is particularly useful if you have routes without an
associated controller or action.

To get started, create a YAML file in `config/purgatory/`, for example:

```yaml
# config/purgatory/post.yaml

# Basic example
post_details:
    class: App\Entity\Post

# Explicit mapping of route parameters
post_details:
    class: App\Entity\Post
    route_params:
        postId: id

# Targeting specific properties
post_details:
    class: App\Entity\Post
    target: [ title, text ]

# Targeting specific methods
post_details:
    class: App\Entity\Post
    target: titleAndAuthor

# Using serialization groups
post_details:
    class: App\Entity\Post
    target: !for_groups [ common ]

# Adding conditional logic with Expression Language
post_details:
    class: App\Entity\Post
    if: 'obj.upvotes > 3000'

# Adding multiple purge subscriptions
post_details:
    - class: App\Entity\Post
      target: [ title, text ]
    - class: App\Entity\Author
      target: name

# Limiting by action type
post_details:
    class: App\Entity\Post
    action: update

# Complex route parameters
posts_list:
    class: App\Entity\Post
    route_params:
        lang: !compound
            - !enum App\Enum\LanguageCodes
            - !raw XK

# Using values provided by a service
posts_list:
    class: App\Entity\Post
    route_params:
        type: !dynamic my_service
```
