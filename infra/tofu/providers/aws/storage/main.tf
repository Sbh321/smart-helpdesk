terraform {
  required_providers {
    aws = { source = "hashicorp/aws", version = "~> 6.65" }
  }
}

resource "aws_s3_bucket" "this" {
  bucket = var.name
}

resource "aws_s3_bucket_public_access_block" "this" {
  bucket                  = aws_s3_bucket.this.id
  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_versioning" "this" {
  bucket = aws_s3_bucket.this.id

  versioning_configuration {
    status = var.versioning ? "Enabled" : "Suspended"
  }
}

# An IAM user whose only permission is this bucket (the application's AWS_ACCESS_KEY_ID).
resource "aws_iam_user" "app" {
  name = "${var.name}-app"
}

resource "aws_iam_user_policy" "app" {
  name = "bucket-access"
  user = aws_iam_user.app.name
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      { Effect = "Allow", Action = ["s3:ListBucket", "s3:GetBucketLocation", "s3:GetBucketCors", "s3:PutBucketCors"], Resource = aws_s3_bucket.this.arn },
      { Effect = "Allow", Action = ["s3:GetObject", "s3:PutObject", "s3:DeleteObject"], Resource = "${aws_s3_bucket.this.arn}/*" },
    ]
  })
}

resource "aws_iam_access_key" "app" {
  user = aws_iam_user.app.name
}
